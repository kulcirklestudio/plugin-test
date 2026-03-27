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
define( 'DB_NAME', 'new git' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

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
define( 'AUTH_KEY',         '4K0%Lf*T`Vr]PTxhXNG3RdDvwfKxouZ9v..0RztoHv@igKiv:lT@qy`_(vz012G`' );
define( 'SECURE_AUTH_KEY',  ':~sN*p?bonXQA/uxu}0FE|mIX1cK=G>6lL[zB,Wpcb{t qa#1e+_}:c*Us;wI;37' );
define( 'LOGGED_IN_KEY',    '<`|,wjQ|1IK|L}h^A2]qiP/<}/`!(C..~L7LQjt$)|EIN@[Eu9D1uW;6g[HXM0/#' );
define( 'NONCE_KEY',        '<kW^k#;D,$7_qhQ&FcMq6N!gudLH9~`Oj+)!0@~:(vEQ~ok(0:M*/a&EJvbXR+(]' );
define( 'AUTH_SALT',        '%IR?YqA=PP;Z)M:RkfYP]c,2^7H:@4S5CkBzLNAj_g+Bt5=+Dy2sS[<te77:%t0]' );
define( 'SECURE_AUTH_SALT', 'GLo]_];n,3)&j6Z5yb<=_(&8K{QTU1=k:VO `&St4+Px0J,I0Ll<SRrgl8+CG/<Z' );
define( 'LOGGED_IN_SALT',   'a1X~-)n3!Zcc5t#a%6xH@KD*:-4LOiJ%faD{EGQ$0-A&[>%G8wQ7&LP!i;Qm>Z;2' );
define( 'NONCE_SALT',       'RQ r;oh)-Jm<Us;bn|kMxEg1c$2ck@?1cu)MKn~5AEU*Bt<D+f~6-B-q`14hUgvH' );

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
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
