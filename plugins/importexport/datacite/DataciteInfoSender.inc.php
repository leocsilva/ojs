<?php

/**
 * @file plugins/importexport/datacite/DataciteInfoSender.php
 *
 * Copyright (c) 2013-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DataciteInfoSender
 * @ingroup plugins_importexport_datacite
 *
 * @brief Scheduled task to send deposits to DataCite.
 */

use PKP\file\FileManager;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;

class DataciteInfoSender extends ScheduledTask
{
    /** @var DataciteExportPlugin $_plugin */
    public $_plugin;

    /**
     * Constructor.
     */
    public function __construct($args)
    {
        PluginRegistry::loadCategory('importexport');
        $plugin = PluginRegistry::getPlugin('importexport', 'DataciteExportPlugin'); /** @var DataciteExportPlugin $plugin */
        $this->_plugin = $plugin;

        if (is_a($plugin, 'DataciteExportPlugin')) {
            $plugin->addLocaleData();
        }

        parent::__construct($args);
    }

    /**
     * @copydoc ScheduledTask::getName()
     */
    public function getName()
    {
        return __('plugins.importexport.datacite.senderTask.name');
    }

    /**
     * @copydoc ScheduledTask::executeActions()
     */
    public function executeActions()
    {
        if (!$this->_plugin) {
            return false;
        }

        $plugin = $this->_plugin;
        $journals = $this->_getJournals();

        foreach ($journals as $journal) {
            $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $journal->getId());
            $doiPubIdPlugin = $pubIdPlugins['doipubidplugin'];

            if ($doiPubIdPlugin->getSetting($journal->getId(), 'enableIssueDoi')) {
                // Get unregistered issues
                $unregisteredIssues = $plugin->getUnregisteredIssues($journal);
                // If there are issues to be deposited
                if (count($unregisteredIssues)) {
                    $this->_registerObjects($unregisteredIssues, 'issue=>datacite-xml', $journal, 'issues');
                }
            }

            if ($doiPubIdPlugin->getSetting($journal->getId(), 'enablePublicationDoi')) {
                // Get unregistered articles
                $unregisteredArticles = $plugin->getUnregisteredArticles($journal);
                // If there are articles to be deposited
                if (count($unregisteredArticles)) {
                    $this->_registerObjects($unregisteredArticles, 'article=>datacite-xml', $journal, 'articles');
                }
            }

            if ($doiPubIdPlugin->getSetting($journal->getId(), 'enableRepresentationDoi')) {
                // Get unregistered galleys
                $unregisteredGalleys = $plugin->getUnregisteredGalleys($journal);
                // If there are galleys to be deposited
                if (count($unregisteredGalleys)) {
                    $this->_registerObjects($unregisteredGalleys, 'galley=>datacite-xml', $journal, 'galleys');
                }
            }
        }
        return true;
    }

    /**
     * Get all journals that meet the requirements to have
     * their DOIs sent to DataCite.
     *
     * @return array
     */
    public function _getJournals()
    {
        $plugin = $this->_plugin;
        $contextDao = Application::getContextDAO(); /** @var JournalDAO $contextDao */
        $journalFactory = $contextDao->getAll(true);

        $journals = [];
        while ($journal = $journalFactory->next()) {
            $journalId = $journal->getId();
            if (!$plugin->getSetting($journalId, 'username') || !$plugin->getSetting($journalId, 'password') || !$plugin->getSetting($journalId, 'automaticRegistration')) {
                continue;
            }

            $doiPrefix = null;
            $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $journalId);
            if (isset($pubIdPlugins['doipubidplugin'])) {
                $doiPubIdPlugin = $pubIdPlugins['doipubidplugin'];
                if (!$doiPubIdPlugin->getSetting($journalId, 'enabled')) {
                    continue;
                }
                $doiPrefix = $doiPubIdPlugin->getSetting($journalId, 'doiPrefix');
            }

            if ($doiPrefix) {
                $journals[] = $journal;
            } else {
                $this->addExecutionLogEntry(__('plugins.importexport.common.senderTask.warning.noDOIprefix', ['path' => $journal->getPath()]), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
            }
        }
        return $journals;
    }


    /**
     * Register objects
     *
     * @param array $objects
     * @param string $filter
     * @param Journal $journal
     * @param string $objectsFileNamePart
     */
    public function _registerObjects($objects, $filter, $journal, $objectsFileNamePart)
    {
        $plugin = $this->_plugin;
        $fileManager = new FileManager();
        foreach ($objects as $object) {
            // export XML
            $exportXml = $plugin->exportXML($object, $filter, $journal);
            // Write the XML to a file.
            // export file name example: datacite-20160723-160036-articles-1-1.xml
            $objectFileNamePart = $objectsFileNamePart . '-' . $object->getId();
            $exportFileName = $plugin->getExportFileName($plugin->getExportPath(), $objectFileNamePart, $journal, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            // Deposit the XML file.
            $result = $plugin->depositXML($object, $journal, $exportFileName);
            if ($result !== true) {
                $this->_addLogEntry($result);
            }
            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
        }
    }

    /**
     * Add execution log entry
     *
     * @param array $result
     */
    public function _addLogEntry($result)
    {
        if (is_array($result)) {
            foreach ($result as $error) {
                assert(is_array($error) && count($error) >= 1);
                $this->addExecutionLogEntry(
                    __($error[0], ['param' => ($error[1] ?? null)]),
                    ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
                );
            }
        } else {
            $this->addExecutionLogEntry(
                __('plugins.importexport.common.register.error.mdsError', ['param' => ' - ']),
                ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING
            );
        }
    }
}
