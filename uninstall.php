<?php
// Seguridad: solo ejecutar durante la desinstalación de WordPress
if (!defined('WP_UNINSTALL_PLUGIN')) exit;

delete_option('pk_optimizador_settings');

// Eliminar transients de "ajustes guardados" de todos los usuarios
$users = get_users( array( 'fields' => 'ID' ) );
foreach ( $users as $user_id ) {
	delete_transient( 'pk_settings_saved_' . $user_id );
}
