<?php

/**
 * @file plugins/reports/counter/classes/reports/CounterReportAR1.inc.php
 *
 * Copyright (c) 2014 University of Pittsburgh
 * Distributed under the GNU GPL v2 or later. For full terms see the file docs/COPYING.
 *
 * @class AR1
 * @ingroup plugins_reports_counter
 *
 * @brief Article Report 1
 */

use APP\core\Application;
use App\core\Services;
use APP\facades\Repo;
use APP\statistics\StatisticsHelper;
use PKP\db\DAORegistry;
use PKP\plugins\PluginRegistry;

import('plugins.reports.counter.classes.CounterReport');

class CounterReportAR1 extends CounterReport
{
    /**
     * Get the report title
     *
     * @return $string
     */
    public function getTitle()
    {
        return __('plugins.reports.counter.ar1.title');
    }

    /**
     * Convert an OJS metrics request to a COUNTER ReportItem
     *
     * @param string|array $columns column (aggregation level) selection
     * @param array $filters report-level filter selection
     * @param array $orderBy order criteria
     * @param null|DBResultRange $range paging specification
     *
     * @see ReportPlugin::getMetrics for more details
     *
     * @return array COUNTER\ReportItem
     */
    public function getReportItems($columns = [], $filters = [], $orderBy = [], $range = null)
    {
        // Columns are fixed for this report
        $defaultColumns = [StatisticsHelper::STATISTICS_DIMENSION_MONTH, StatisticsHelper::STATISTICS_DIMENSION_SUBMISSION_ID];
        if ($columns && array_diff($columns, $defaultColumns)) {
            $this->setError(new Exception(__('plugins.reports.counter.exception.column'), COUNTER_EXCEPTION_WARNING | COUNTER_EXCEPTION_BAD_COLUMNS));
        }
        // Check filters for correct context(s)
        $validFilters = $this->filterForContext($filters);
        // Filters defaults to last month, but can be provided by month or by day (which is defined in the $columns)
        if (!isset($filters['dateStart']) && !isset($filters['dateEnd'])) {
            $validFilters['dateStart'] = date_format(date_create('first day of previous month'), 'Ymd');
            $validFilters['dateEnd'] = date_format(date_create('last day of previous month'), 'Ymd');
        } elseif (!isset($filters['dateStart']) || !isset($filters['dateEnd'])) {
            // either start or end date not set
            $this->setError(new Exception(__('plugins.reports.counter.exception.filter'), COUNTER_EXCEPTION_WARNING | COUNTER_EXCEPTION_BAD_FILTERS));
        } elseif (isset($filters['dateStart']) && isset($filters['dateEnd'])) {
            $validFilters['dateStart'] = $filters['dateStart'];
            $validFilters['dateEnd'] = $filters['dateEnd'];
            unset($filters['dateStart']);
            unset($filters['dateEnd']);
        }
        if (!isset($filters['assocTypes'])) {
            $validFilters['assocTypes'] = [Application::ASSOC_TYPE_SUBMISSION_FILE];
            unset($filters['assocTypes']);
        } elseif ($filters['assocTypes'] != Application::ASSOC_TYPE_SUBMISSION_FILE) {
            $this->setError(new Exception(__('plugins.reports.counter.exception.filter'), COUNTER_EXCEPTION_ERROR | COUNTER_EXCEPTION_BAD_FILTERS));
        }
        // AR1 could be filtered to the Journal, Issue, or Article level
        foreach ($filters as $key => $filter) {
            switch ($key) {
                case 'contextIds':
                case 'issueIds':
                case 'submissionIds':
                    $validFilters[$key] = [$filter];
                    unset($filters[$key]);
            }
        }
        // Unrecognized filters raise an error
        if (array_keys($filters)) {
            $this->setError(new Exception(__('plugins.reports.counter.exception.filter'), COUNTER_EXCEPTION_WARNING | COUNTER_EXCEPTION_BAD_FILTERS));
        }
        // Identify submissions which should be included in the results when issue IDs are passed
        if (isset($validFilters['issueIds'])) {
            $submissionIds = isset($validFilters['submissionIds']) ?? [];
            $issueIdsSubmissionIds = Repo::submission()->getIds(
                Repo::submission()
                    ->getCollector()
                    ->filterByContextIds([Application::get()->getRequest()->getContext()->getId()])
                    ->filterByStatus([\APP\submission\Submission::STATUS_PUBLISHED])
                    ->filterByIssueIds($validFilters['issueIds'])
            )->toArray();

            if (!empty($submissionIds)) {
                $submissionIds = array_intersect($submissionIds, $issueIdsSubmissionIds);
            } else {
                $submissionIds = $issueIdsSubmissionIds;
            }
            if (!empty($submissionIds)) {
                $validFilters['submissionIds'] = $submissionIds;
            } else {
                $this->setError(new Exception(__('plugins.reports.counter.exception.noData'), COUNTER_EXCEPTION_ERROR | COUNTER_EXCEPTION_NO_DATA));
                return [];
            }
        }
        // TODO: range
        $results = Services::get('publicationStats')
            ->getQueryBuilder($validFilters)
            ->getSum($defaultColumns)
            ->orderBy(StatisticsHelper::STATISTICS_DIMENSION_SUBMISSION_ID, StatisticsHelper::STATISTICS_ORDER_DESC)
            ->orderBy(StatisticsHelper::STATISTICS_DIMENSION_MONTH, StatisticsHelper::STATISTICS_ORDER_ASC)
            ->get()->toArray();
        $reportItems = [];
        if ($results) {
            // We'll create a new Report Item with these Metrics on a article change
            $metrics = [];
            $lastArticle = 0;
            foreach ($results as $rs) {
                // Article changes trigger a new ReportItem
                if ($lastArticle != $rs->{StatisticsHelper::STATISTICS_DIMENSION_SUBMISSION_ID}) {
                    if ($lastArticle != 0 && $metrics) {
                        $item = $this->_createReportItem($lastArticle, $metrics);
                        if ($item) {
                            $reportItems[] = $item;
                        } else {
                            $this->setError(new Exception(__('plugins.reports.counter.exception.partialData'), COUNTER_EXCEPTION_WARNING | COUNTER_EXCEPTION_PARTIAL_DATA));
                        }
                        $metrics = [];
                    }
                }
                $metrics[] = $this->createMetricByMonth($rs->{StatisticsHelper::STATISTICS_DIMENSION_MONTH}, [new COUNTER\PerformanceCounter('ft_total', $rs->{StatisticsHelper::STATISTICS_METRIC})]);
                $lastArticle = $rs->{StatisticsHelper::STATISTICS_DIMENSION_SUBMISSION_ID};
            }
            // Capture the last unprocessed ItemPerformance and ReportItem entries, if applicable
            if ($metrics) {
                $item = $this->_createReportItem($lastArticle, $metrics);
                if ($item) {
                    $reportItems[] = $item;
                } else {
                    $this->setError(new Exception(__('plugins.reports.counter.exception.partialData'), COUNTER_EXCEPTION_WARNING | COUNTER_EXCEPTION_PARTIAL_DATA));
                }
            }
        } else {
            $this->setError(new Exception(__('plugins.reports.counter.exception.noData'), COUNTER_EXCEPTION_ERROR | COUNTER_EXCEPTION_NO_DATA));
        }
        return $reportItems;
    }

    /**
     * Given a submissionId and an array of COUNTER\Metrics, return a COUNTER\ReportItems
     *
     * @param int $submissionId
     * @param array $metrics COUNTER\Metric array
     *
     * @return mixed COUNTER\ReportItems or false
     */
    private function _createReportItem($submissionId, $metrics)
    {
        $article = Repo::submission()->get($submissionId);
        if (!$article) {
            return false;
        }
        $title = $article->getLocalizedTitle();
        $journalId = $article->getContextId();
        $journalDao = DAORegistry::getDAO('JournalDAO'); /** @var JournalDAO $journalDao */
        $journal = $journalDao->getById($journalId);
        if (!$journal) {
            return false;
        }
        $journalName = $journal->getLocalizedName();
        $journalPubIds = [];
        foreach (['print', 'online'] as $issnType) {
            if ($journal->getData($issnType . 'Issn')) {
                try {
                    $journalPubIds[] = new COUNTER\Identifier(ucfirst($issnType) . '_ISSN', $journal->getData($issnType . 'Issn'));
                } catch (Exception $ex) {
                    // Just ignore it
                }
            }
        }
        $journalPubIds[] = new COUNTER\Identifier(COUNTER_LITERAL_PROPRIETARY, $journal->getPath());
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $journalId);
        $articlePubIds = [];
        $articlePubIds[] = new COUNTER\Identifier(COUNTER_LITERAL_PROPRIETARY, (string) $submissionId);
        foreach ($pubIdPlugins as $pubIdPlugin) {
            $pubId = $article->getStoredPubId($pubIdPlugin->getPubIdType(), true);
            if ($pubId) {
                switch ($pubIdPlugin->getPubIdType()) {
                    case 'doi':
                        try {
                            $articlePubIds[] = new COUNTER\Identifier(strtoupper($pubIdPlugin->getPubIdType()), $pubId);
                        } catch (Exception $ex) {
                            // Just ignore it
                        }
                        break;
                    default:
                }
            }
        }
        $reportItem = [];
        try {
            $reportItem = new COUNTER\ReportItems(
                __('common.software'),
                $title,
                COUNTER_LITERAL_ARTICLE,
                $metrics,
                new COUNTER\ParentItem($journalName, COUNTER_LITERAL_JOURNAL, $journalPubIds),
                $articlePubIds
            );
        } catch (Exception $e) {
            $this->setError($e, COUNTER_EXCEPTION_ERROR | COUNTER_EXCEPTION_INTERNAL);
        }
        return $reportItem;
    }
}
