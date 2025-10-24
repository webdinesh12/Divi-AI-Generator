<?php

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'codex');

/** Database username */
define('DB_USER', 'root');

/** Database password */
define('DB_PASSWORD', '');

/** Database hostname */
define('DB_HOST', 'localhost');

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'sedskL@z$ZMiFB[j{CzhHgV,aHSLG3GvI=d9D/^ ^.f?7)X9H>MsJQjNbwD;VAtX');
define('SECURE_AUTH_KEY',  '{U|9?AewdcODG0}9K`rY@t+`WxUq,s~5[`09JY2(9:D|J6kyd6.,#4!0=u#@61:x');
define('LOGGED_IN_KEY',    'Y<cW[h?i4iFqppgESpw}D%pNyMyb?*cE$^B7K*l~&a X?)7LxOzRB0(HSB<h$VNh');
define('NONCE_KEY',        'S%^-tuAKGZi2t5ya/P}soG}C^b,u:Z$OSiE`39^|,2S#M@(eEv}v2{6-];=&tnA]');
define('AUTH_SALT',        '@5Db=pCTBdlzQ(IZ~?QMoe%g$0uiU|j_3YX.jZSYv+m~P]UWL e-*G?A,8SK {Bj');
define('SECURE_AUTH_SALT', 'w%yc:}y>`v%+D#MHKR|jQ[Y.3f?BLmZpJB6~fQ%_cRj^,zSk`*)(w}B^B.*[YJEq');
define('LOGGED_IN_SALT',   'czJV$.sA{RK#KgtqPAia&7GD[Osu1|yXi<^=C>d}8}~_?u}tZb(!vorsOQ`{S9if');
define('NONCE_SALT',       'qZ93CiYLO@0a_TIC)VjeaWG)m%%mvZppE,/#OK|LXr|V45H.;Z&)&*^amPr$tcQ1');

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */

define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', true);

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if (! defined('ABSPATH')) {
	define('ABSPATH', __DIR__ . '/');
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
