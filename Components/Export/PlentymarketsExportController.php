<?php
/**
 * plentymarkets shopware connector
 * Copyright © 2013 plentymarkets GmbH
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License, supplemented by an additional
 * permission, and of our proprietary license can be found
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "plentymarkets" is a registered trademark of plentymarkets GmbH.
 * "shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, titles and interests in the
 * above trademarks remain entirely with the trademark owners.
 *
 * @copyright  Copyright (c) 2013, plentymarkets GmbH (http://www.plentymarkets.com)
 * @author     Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */

require_once PY_COMPONENTS . 'Export/PlentymarketsExportException.php';
require_once PY_COMPONENTS . 'Export/Status/PlentymarketsExportStatusController.php';

/**
 * The class PlentymarketsExportController does the actual export for different cronjobs e.g. in the class PlentymarketsCronjobController.
 * It uses the different export entities in /Export/Entity, for example PlentymarketsExportEntityCustomer.
 *
 * @author Daniel Bächtle <daniel.baechtle@plentymarkets.com>
 */
class PlentymarketsExportController
{

	/**
	 *
	 * @var integer
	 */
	const DEFAULT_CHUNKS_PER_RUN = -1;

	/**
	 *
	 * @var integer
	 */
	const DEFAULT_CHUNK_SIZE = 250;

	/**
	 * PlentymarketsExportController object data.
	 *
	 * @var PlentymarketsExportController
	 */
	protected static $Instance;

	/**
	 *
	 * @var array
	 */
	protected static $mapping = array(
		'ItemCategory' => 'plenty_mapping_category',
		'ItemAttribute' => array(
			'plenty_mapping_attribute_group',
			'plenty_mapping_attribute_option',
		),
		'ItemProperty' => array(
			'plenty_mapping_property',
			'plenty_mapping_property_group',
		),
		'ItemProducer' => 'plenty_mapping_producer',
		'Item' => array(
			'plenty_mapping_item',
			'plenty_mapping_item_variant',
		),
		'Customer' => 'plenty_mapping_customer_billing_address',
		'ItemCrossSelling' => array()
	);

	/**
	 * PlentymarketsConfig object data.
	 *
	 * @var PlentymarketsConfig
	 */
	protected $Config;

	/**
	 *
	 * @var PlentymarketsExportStatusController
	 */
	protected $StatusController;

	/**
	 * Indicates whether an export process is running.
	 *
	 * @var boolean
	 */
	protected $isRunning = false;

	/**
	 * Indicates whether an export may run or not.
	 *
	 * @var boolean
	 */
	protected $mayRun = false;

	/**
	 * Prepares config data and checks different conditions like finished mapping.
	 */
	protected function __construct()
	{
		//
		$this->Config = PlentymarketsConfig::getInstance();
		$this->StatusController = PlentymarketsExportStatusController::getInstance();

		// Check whether a process is running
		$this->isRunning = (boolean) $this->Config->getIsExportRunning(false);

		// Check whether settings and mapping are done
		$this->mayRun = PlentymarketsMappingController::isComplete() && $this->Config->isComplete();
	}

	/**
	 * The returned value indicates whether the settings and mapping are done.
	 *
	 * @return boolean
	 */
	public function isComplete()
	{
		return $this->StatusController->isFinished();
	}


	/**
	 * Resets an export status
	 *
	 * @param string $entity
	 */
	public function reset($entity, $resetRunning=true)
	{
		// Reset running status
		if ($resetRunning)
		{
			$this->Config->setExportEntityPending(false);
			$this->Config->setIsExportRunning(0);
		}

		$methodStatus = sprintf('set%sExportStatus', $entity);
		$this->Config->$methodStatus('open');

		// Start
		$methodStart = sprintf('set%sExportTimestampStart', $entity);
		$this->Config->$methodStart(0);

		// Finished
		$methodStart = sprintf('set%sExportTimestampFinished', $entity);
		$this->Config->$methodStart(0);

		// Error
		$methodError = sprintf('erase%sExportLastErrorMessage', $entity);
		$this->Config->$methodError();

		// Last chunk
		$methodLastChunk = sprintf('erase%sExportLastChunk', $entity);
		$this->Config->$methodLastChunk();
	}

	/**
	 * Erases an export status and the mapping
	 *
	 * @param string $entity
	 */
	public function erase($entity)
	{
		// Status
		$this->reset($entity, false);

		// -1 is needed, so that the erase button is invisible
		$methodStart = sprintf('erase%sExportTimestampStart', $entity);
		$this->Config->$methodStart();

		// Delete Mapping
		foreach ((array) self::$mapping[$entity] as $tableName)
		{
			try
			{
				Shopware()->Db()->delete($tableName);
				PlentymarketsLogger::getInstance()->message('Export:Initial:' . $entity, 'Truncated table ' . $tableName);
			}
			catch (Exception $E)
			{
			}
		}
	}

	/**
	 * This method checks different export states like if an other export is running at the moment, or
	 * an export has already announced. In case of complied conditions the actual export gets the state "pending".
	 *
	 * @param string $entity
	 * @throws PlentymarketsExportException
	 */
	public function announce($entity)
	{
		if ($this->isRunning == true)
		{
			throw new PlentymarketsExportException('Another export is running at this very moment', 2510);
		}

		// Check whether another export is waiting to be executed
		$waiting = $this->Config->getExportEntityPending(false);
		if ($waiting == $entity)
		{
			throw new PlentymarketsExportException('The export has already been announced', 2530);
		}

		if ($waiting != false)
		{
			throw new PlentymarketsExportException('Another export is waiting to be carried out', 2540);
		}

		// Check whether settings and mapping is complete
		if ($this->mayRun == false)
		{
			throw new PlentymarketsExportException('Either the mapping or the settings is not finished', 2520);
		}

		// Check whether or not the order is correct
		if (!$this->StatusController->mayAnnounceEntity($entity))
		{
			throw new PlentymarketsExportException('The announcement could not be performed right now', 2550);
		}

		//
		$this->Config->setExportEntityPending($entity);

		$method = sprintf('set%sExportStatus', $entity);
		$this->Config->$method('pending');

		//
		$this->Config->eraseInitialExportLastCallTimestamp();
	}

	/**
	 * Starts the actual pending export.
	 *
	 * @throws PlentymarketsExportException
	 */
	public function export()
	{
		if ($this->isRunning == true)
		{
			throw new PlentymarketsExportException('Another export is running at this very moment', 2510);
		}

		// Check whether settings and mapping is complete
		if ($this->mayRun == false)
		{
			throw new PlentymarketsExportException('Either the mapping or the settings is not finished', 2520);
		}

		$entity = $this->Config->getExportEntityPending(false);

		if ($entity == false)
		{
			// No exception.. or the log will ne spammed
			return;
		}

		// Set the running flag and delete the last call timestmap
		$this->Config->eraseExportEntityPending();
		$this->Config->setIsExportRunning(1);
		$this->Config->eraseInitialExportLastCallTimestamp();

		PlentymarketsSoapClient::getInstance()->setTimestampConfigKey('InitialExportLastCallTimestamp');

		//
		PlentymarketsLogger::getInstance()->message('Export:Initial:' . $entity, 'Starting');

		try
		{
			switch ($entity)
			{
				// Entities
				case 'ItemCategory':
				case 'ItemAttribute':
				case 'ItemProperty':
				case 'ItemProducer':
				case 'ItemCrossSelling':
					$this->exportEntity($entity);
					break;

				// Items
				case 'Item':
					$this->exportItems();
					break;

				// Customers
				case 'Customer':
					$this->exportCustomers();
					break;
			}

			PlentymarketsLogger::getInstance()->message('Export:Initial:' . $entity, 'Done!');
		}
		catch (PlentymarketsExportException $E)
		{
			PlentymarketsLogger::getInstance()->error('Export:Initial:' . $entity, $E->getMessage(), $E->getCode());

			$method = sprintf('set%sExportLastErrorMessage', $entity);
			$this->Config->$method($E->getMessage());

			$method = sprintf('set%sExportStatus', $entity);
			$this->Config->$method('error');
		}

		PlentymarketsSoapClient::getInstance()->setTimestampConfigKey(null);
		$this->Config->setIsExportRunning(0);
	}

	/**
	 * If an instance of PlentymarketsExportController exists, it returns this instance.
	 * Else it creates a new instance of PlentymarketsExportController.
	 *
	 * @return PlentymarketsExportController
	 */
	public static function getInstance()
	{
		if (!self::$Instance instanceof self)
		{
			self::$Instance = new self();
		}
		return self::$Instance;
	}

	/**
	 * Exports customer and delivery address items data.
	 */
	protected function exportCustomers()
	{
		require_once PY_COMPONENTS . 'Export/Controller/PlentymarketsExportControllerCustomer.php';
		PlentymarketsExportControllerCustomer::getInstance()->run();
	}

	/**
	 * Exports images, variants, properties item data and items base to make sure, that the corresponding items data exist.
	 */
	protected function exportItems()
	{
		require_once PY_COMPONENTS . 'Export/Controller/PlentymarketsExportControllerItem.php';
		PlentymarketsExportControllerItem::getInstance()->run();
	}

	/**
	 * Exports one Entity.
	 *
	 * @param string $entity
	 */
	protected function exportEntity($entity)
	{
		$class = sprintf('PlentymarketsExportEntity%s', $entity);

		require_once PY_COMPONENTS . 'Export/Entity/' . $class . '.php';

		// Set running
		$methodStatus = sprintf('set%sExportStatus', $entity);
		$this->Config->$methodStatus('running');

		// Start
		$methodStart = sprintf('set%sExportTimestampStart', $entity);
		$this->Config->$methodStart(time());

		// Run the export
		$PlentymarketsExport = new $class();
		$PlentymarketsExport->export();

		// Finished
		$methodStart = sprintf('set%sExportTimestampFinished', $entity);
		$this->Config->$methodStart(time());

		$this->Config->$methodStatus('success');
	}
}
