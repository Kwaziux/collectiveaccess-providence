<?php

/* ----------------------------------------------------------------------
 * app/controllers/find/FindOccurrencesController.php : controller for object search request handling
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2009-2015 Whirl-i-Gig
 *
 * For more information visit http://www.CollectiveAccess.org
 *
 * This program is free software; you may redistribute it and/or modify it under
 * the terms of the provided license as published by Whirl-i-Gig
 *
 * CollectiveAccess is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTIES whatsoever, including any implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
 *
 * This source code is free and modifiable under the terms of 
 * GNU General Public License. (http://www.gnu.org/copyleft/gpl.html). See
 * the "license.txt" file for details, or visit the CollectiveAccess web site at
 * http://www.CollectiveAccess.org
 *
 * ----------------------------------------------------------------------
 */
require_once(__CA_LIB_DIR__ . "/BaseSearchController.php");
require_once(__CA_LIB_DIR__ . "/Search/OccurrenceSearch.php");
require_once(__CA_LIB_DIR__ . "/Browse/OccurrenceBrowse.php");

class SearchOccurrencesController extends BaseSearchController
{
    # -------------------------------------------------------
    /**
     * Name of subject table (ex. for an object search this is 'ca_objects')
     */
    protected $ops_tablename = 'ca_occurrences';

    /**
     * Number of items per search results page
     */
    protected $opa_items_per_page = array(10, 20, 30, 40, 50);

    /**
     * List of search-result views supported for this find
     * Is associative array: keys are view labels, values are view specifier to be incorporated into view name
     */
    protected $opa_views;

    /**
     * Name of "find" used to defined result context for ResultContext object
     * Must be unique for the table and have a corresponding entry in find_navigation.conf
     */
    protected $ops_find_type = 'basic_search';

    # -------------------------------------------------------
    public function __construct(&$po_request, &$po_response, $pa_view_paths = null)
    {
        parent::__construct($po_request, $po_response, $pa_view_paths);
        if ($this->request->config->get('enable_full_thumbnail_result_views_for_ca_occurrences_search')) {
            $this->opa_views = array(
                'list' => _t('list'),
                'thumbnail' => _t('thumbnails'),
                'full' => _t('full')
            );
        } else {
            $this->opa_views = array(
                'list' => _t('list')
            );
        }
        $this->opo_browse = new OccurrenceBrowse($this->opo_result_context->getParameter('browse_id'), 'providence');

        AssetLoadManager::register('imageScroller');
        AssetLoadManager::register('tabUI');
        AssetLoadManager::register('panel');
    }
    # -------------------------------------------------------

    /**
     * Search handler (returns search form and results, if any)
     * Most logic is contained in the BaseSearchController->Search() method; all you usually
     * need to do here is instantiate a new subject-appropriate subclass of BaseSearch
     * (eg. OccurrenceSearch for objects, EntitySearch for entities) and pass it to BaseSearchController->Search()
     */
    public function Index($pa_options = null)
    {
        $pa_options['search'] = $this->opo_browse;
        return parent::Index($pa_options);
    }
    # -------------------------------------------------------
    # Sidebar info handler
    # -------------------------------------------------------
    /**
     * Returns "search tools" widget
     */
    public function Tools($pa_parameters)
    {
        // pass instance of subject-appropriate search object as second parameter (ex. for an object search this is an instance of OccurrenceSearch()
        return parent::Tools($pa_parameters);
    }
    # -------------------------------------------------------

    /**
     *
     */
    public function _getSubTypeActionNav($pa_item)
    {
        return [
            [
                'displayName' => _t('Search'),
                "default" => ['module' => 'find', 'controller' => 'SearchOccurrences', 'action' => 'Index'],
                'parameters' => array(
                    'type_id' => $pa_item['item_id'],
                    'reset' => $this->request->getUser()->getPreference('persistent_search')
                ),
                'is_enabled' => true,
            ],
            [
                'displayName' => _t('Advanced search'),
                "default" => ['module' => 'find', 'controller' => 'SearchOccurrencesAdvanced', 'action' => 'Index'],
                'useActionInPath' => 1,
                'parameters' => array(
                    'type_id' => $pa_item['item_id'],
                    'reset' => $this->request->getUser()->getPreference('persistent_search')
                ),
                'is_enabled' => true,
            ],
            [
                'displayName' => _t('Browse'),
                "default" => ['module' => 'find', 'controller' => 'BrowseOccurrences', 'action' => 'Index'],
                'parameters' => array(
                    'type_id' => $pa_item['item_id']
                ),
                'is_enabled' => true,
            ]
        ];
    }
    # -------------------------------------------------------
}