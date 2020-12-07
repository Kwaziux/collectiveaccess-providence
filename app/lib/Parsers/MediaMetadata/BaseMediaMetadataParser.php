<?php
/** ---------------------------------------------------------------------
 * app/lib/Parsers/MediaMetadata/XMPMediaMetadata.php :
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2011 Whirl-i-Gig
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
 * @package CollectiveAccess
 * @subpackage Core
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License version 3
 *
 * ----------------------------------------------------------------------
 */

/**
 *
 */
abstract class BaseMediaMetadataParser
{
    # -------------------------------------------------------

    # -------------------------------------------------------
    public function __construct()
    {
        // noop
    }

    # -------------------------------------------------------
    public function parse($ps_filepath)
    {
        return false;
    }

    # -------------------------------------------------------
    public function write($ps_filepath = null)
    {
        return false;
    }

    # -------------------------------------------------------
    public function set($ps_field, $ps_value)
    {
        return false;
    }
    # -------------------------------------------------------
}

?>