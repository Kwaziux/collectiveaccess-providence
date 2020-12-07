<?php
/* ----------------------------------------------------------------------
 * manage/export/exporter_delete_html.php:
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2013 Whirl-i-Gig
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

$t_exporter     = $this->getVar( 't_exporter' );
$vn_exporter_id = $this->getVar( 'exporter_id' );
?>
<div class="sectionBox">
	<?php
	print caDeleteWarningBox( $this->request, $t_exporter, $t_exporter->getLabelForDisplay( false ), 'batch',
		'MetadataImport', 'Index', array( 'exporter_id' => $vn_exporter_id ) );
	?>
</div>
