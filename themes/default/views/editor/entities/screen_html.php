<?php
/* ----------------------------------------------------------------------
 * app/views/editor/entities/screen_html.php : 
 * ----------------------------------------------------------------------
 * CollectiveAccess
 * Open-source collections management software
 * ----------------------------------------------------------------------
 *
 * Software by Whirl-i-Gig (http://www.whirl-i-gig.com)
 * Copyright 2007-2020 Whirl-i-Gig
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
$t_entity     = $this->getVar( 't_subject' );
$vn_entity_id = $this->getVar( 'subject_id' );
$vn_above_id  = $this->getVar( 'above_id' );
$vn_after_id  = $this->getVar( 'after_id' );

$vb_can_edit   = $t_entity->isSaveable( $this->request );
$vb_can_delete = $t_entity->isDeletable( $this->request );

$vs_rel_table   = $this->getVar( 'rel_table' );
$vn_rel_type_id = $this->getVar( 'rel_type_id' );
$vn_rel_id      = $this->getVar( 'rel_id' );

if ( $vb_can_edit ) {
	$va_cancel_parameters = ( $vn_entity_id ? array( 'entity_id' => $vn_entity_id )
		: array( 'type_id' => $t_entity->getTypeID() ) );
	print $vs_control_box = caFormControlBox(
		caFormSubmitButton( $this->request, __CA_NAV_ICON_SAVE__, _t( "Save" ), 'EntityEditorForm' ) . ' ' .
		( $this->getVar( 'show_save_and_return' ) ? caFormSubmitButton( $this->request, __CA_NAV_ICON_SAVE__,
			_t( "Save and return" ), 'EntityEditorForm', array( 'isSaveAndReturn' => true ) ) : '' ) . ' ' .
		caFormNavButton( $this->request, __CA_NAV_ICON_CANCEL__, _t( "Cancel" ), '', 'editor/entities', 'EntityEditor',
			'Edit/' . $this->request->getActionExtra(), $va_cancel_parameters ),
		( $this->getVar( 'show_show_notifications' ) ? caFormJSButton( $this->request, __CA_NAV_ICON_ALERT__,
			_t( "Show editor alerts" ), '', [ 'class' => 'caEditorFormNotifications' ] ) : '' ),
		( ( intval( $vn_entity_id ) > 0 ) && $vb_can_delete ) ? caFormNavButton( $this->request, __CA_NAV_ICON_DELETE__,
			_t( "Delete" ), '', 'editor/entities', 'EntityEditor', 'Delete/' . $this->request->getActionExtra(),
			array( 'entity_id' => $vn_entity_id ) ) : ''
	);
}
?>
	<div class="sectionBox">
		<?php
		print caFormTag( $this->request, 'Save/' . $this->request->getActionExtra() . '/entity_id/' . $vn_entity_id,
			'EntityEditorForm', null, 'POST', 'multipart/form-data' );

		$va_form_elements = $t_entity->getBundleFormHTMLForScreen( $this->request->getActionExtra(), array(
			'request'  => $this->request,
			'formName' => 'EntityEditorForm'
		), $va_bundle_list );

		print join( "\n", $va_form_elements );

		if ( $vb_can_edit ) {
			print $vs_control_box;
		}
		?>
		<input type='hidden' name='entity_id' value='<?php print $vn_entity_id; ?>'/>
		<input type='hidden' name='above_id' value='<?php print $vn_above_id; ?>'/>
		<input id='isSaveAndReturn' type='hidden' name='is_save_and_return' value='0'/>
		<input type='hidden' name='rel_table' value='<?php print $vs_rel_table; ?>'/>
		<input type='hidden' name='rel_type_id' value='<?php print $vn_rel_type_id; ?>'/>
		<input type='hidden' name='rel_id' value='<?php print $vn_rel_id; ?>'/>
		<input type='hidden' name='after_id' value='<?php print $vn_after_id; ?>'/>
		<?php
		if ( $this->request->getParameter( 'rel', pInteger ) ) {
			?>
			<input type='hidden' name='rel' value='1'/>
			<?php
		}
		?>
		</form>
	</div>

	<div class="editorBottomPadding"><!-- empty --></div>

<?php print caSetupEditorScreenOverlays( $this->request, $t_entity, $va_bundle_list ); ?>
