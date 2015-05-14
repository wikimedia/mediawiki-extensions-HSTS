<?php

/**
 * Extension enabling the HSTS on a MediaWiki website on a per-user basis
 *
 * Note that if you intend to activate HSTS on the whole website, it will be
 * more efficient and robust to add it directly in the server configuration.
 *
 * @file
 * @ingroup Extensions
 * @author Seb35
 * @licence WTFPL 2.0
 * /

/* Check if we are being called directly */
if ( !defined( 'MEDIAWIKI' ) ) {
	die( 'This file is an extension to MediaWiki and thus not a valid entry point.' );
}

/**
 * CONFIGURATION
 * These variables may be overridden in LocalSettings.php after you include the
 * extension file.
 */

/**
 * Mandatory HSTS for anonymous users
 */
$wgHSTSForAnons = false;

/**
 * Mandatory HSTS for logged-in users
 */
$wgHSTSForUsers = false;

/**
 * HSTS parameter max-age (time-to-live of the default-HTTPS protection)
 *
 * This can be either:
 * - a number (canonical number of seconds before expiration of HSTS)
 *   (e.g. 3600 for one hour), or
 * - a date when HSTS will expire (e.g. just before certificate expiration)
 *   (e.g. "2014-09-24T00:00:00Z" in ISO 8601 format, see wfTimestamp for
 *   other date formats).
 *
 * Note that in the second case the header is dynamical, so you may want to
 * configure accordingly your cache servers for a consistent user experience,
 * particularly given the authoritative HSTS header is the last sent, even
 * if shorter.
 */
$wgHSTSMaxAge = 30*86400;

/**
 * HSTS parameter includeSubDomains (whether to include the default-HTTPS
 * protection for subdomains of the visited website)
 */
$wgHSTSIncludeSubdomains = false;

/**
 * Default value of HSTS preference for logged-in users
 * (only useful if $wgHSTSForUsers is false)
 * 0 = opt-out
 * 1 = opt-in
 */
$wgDefaultUserOptions['hsts'] = 0;

/**
 * Enable HSTS as a Beta Feature (see BetaFeatures extension)
 * If true, the beta feature will have precedence over the
 * user preference, which will no more be visible.
 */
$wgHSTSBetaFeature = false;


/** REGISTRATION */
$wgExtensionCredits['other'][] = array(
	'path' => __FILE__,
	'name' => 'HSTS',
	'author' => 'Seb35',
	'version' => '1.1.1',
	'url' => 'https://www.mediawiki.org/wiki/Extension:HSTS',
	'license-name' => 'WTFPL',
	'descriptionmsg' => 'hsts-desc',
);

$wgAutoloadClasses['HSTSExtension'] = __DIR__ . '/HSTS.php';

$wgMessagesDirs['HSTS'] = __DIR__ . '/i18n';

$wgHooks['GetPreferences'][] = 'HSTSExtension::getPreferences';
$wgHooks['GetBetaFeaturePreferences'][] = 'HSTSExtension::getBetaFeaturePreferences';
$wgHooks['BeforePageDisplay'][] = 'HSTSExtension::addHeader';


/* CODE */
class HSTSExtension {

	/**
	 * Add the HSTS preference
	 *
	 * @var User $user Current user
	 * @var array $preferences Description of the preferences
	 * @return bool true
	 */
	public static function getPreferences( $user, &$preferences ) {
		global $wgHSTSBetaFeature, $wgHSTSForUsers;

		// If HSTS is activated as a Beta Feature, do not add it here
		if( AutoLoader::loadClass( 'BetaFeatures' ) && $wgHSTSBetaFeature ) {
			return true;
		}

		// If HSTS is mandatory, do not display the choice
		if( $wgHSTSForUsers ) {
			return true;
		}

		// Add the checkbox in the 'basic informations' section
		$preferences['hsts'] = array(
			'type' => 'toggle',
			'label-message' => 'hsts-tog',
			'section' => 'personal/info'
		);

		// Enable this preference only if we are on HTTPS
		if( $user->getRequest()->detectProtocol() !== 'https' ) {
			$preferences['hsts']['label-message'] = 'hsts-https-tog';
			$preferences['hsts']['disabled'] = true;
		}

		return true;
	}

	/**
	 * Add the HSTS beta feature
	 *
	 * @var User $user Current user
	 * @var array $preferences Description of the Beta Features
	 * @return bool true
	 *
	 * @todo Add a screenshot (a padlock?)
	 */
	public static function getBetaFeaturePreferences( $user, &$preferences ) {
		global $wgHSTSBetaFeature, $wgHSTSForUsers;

		// If HSTS is activated as a Beta Feature, do not add it here
		if( !$wgHSTSBetaFeature ) {
			return true;
		}

		// If HSTS is mandatory, do not display the choice
		if( $wgHSTSForUsers ) {
			return true;
		}

		$preferences['hsts'] = array(
			'label-message' => 'hsts-beta-feature-message',
			'desc-message' => 'hsts-beta-feature-description',
			'info-link' => 'https://www.mediawiki.org/wiki/Extension:HSTS',
			'discussion-link' => 'https://www.mediawiki.org/wiki/Extension_talk:HSTS',
			'requirements' => array( 'betafeatures' => array( 'prefershttps' ) )
		);

		return true;
	}

	/**
	 * Add the STS header
	 *
	 * @var OutputPage $output Output page object
	 * @return bool true
	 */
	public static function addHeader( $output ) {
		global $wgHSTSForAnons, $wgHSTSForUsers, $wgHSTSIncludeSubdomains, $wgHSTSMaxAge;

		// Check if the user will get STS header
		if(
			$output->getRequest()->detectProtocol() !== 'https'
			|| ( $output->getUser()->isAnon() && !$wgHSTSForAnons )
			|| ( $output->getUser()->isLoggedIn() && !$wgHSTSForUsers && !$output->getUser()->getOption( 'hsts' ) )
		) {
			return true;
		}

		// Compute the max-age property
		if( is_int( $wgHSTSMaxAge ) ) {
			$maxage = max( $wgHSTSMaxAge, 0 );
		} else {
			$maxage = wfTimestamp( TS_UNIX, $wgHSTSMaxAge );
			if( $maxage !== false ) {
				$maxage -= wfTimestamp();
			} else {
				wfDebug( '[HSTS] Bad value of the parameter $wgHSTSMaxAge: must be an integer or a date.' );
				return true;
			}
			if( $maxage < 0 ) {
				wfDebug( '[HSTS] Expired date; HSTS has been lost for all users, apart if externally added in the server configuration.' );
				return true;
			}
		}

		$header = 'Strict-Transport-Security: max-age=' . $maxage .
			( $wgHSTSIncludeSubdomains ? '; includeSubDomains' : '' );
		// Output the header
		$output->getRequest()->response()->header( $header );
		wfDebug( '[HSTS] ' . $header );

		return true;
	}
}

