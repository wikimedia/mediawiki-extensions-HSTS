<?php

namespace MediaWiki\Extension\HSTS;

use ExtensionRegistry;
use MediaWiki\Config\Config;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Preferences\Hook\GetPreferencesHook;
use MediaWiki\User\Options\UserOptionsLookup;
use MediaWiki\User\User;
use Skin;

class Hooks implements
	GetPreferencesHook,
	BeforePageDisplayHook
{
	/** @var Config */
	private $config;

	/** @var UserOptionsLookup */
	private $userOptionsLookup;

	/**
	 * @param Config $config
	 * @param UserOptionsLookup $userOptionsLookup
	 */
	public function __construct(
		Config $config,
		UserOptionsLookup $userOptionsLookup
	) {
		$this->config = $config;
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * Add the HSTS preference
	 *
	 * @param User $user Current user
	 * @param array &$preferences Description of the preferences
	 * @return bool|void True or no return value to continue or false to abort
	 */
	public function onGetPreferences( $user, &$preferences ) {
		// If HSTS is activated as a Beta Feature, do not add it here
		if (
			$this->config->get( 'HSTSBetaFeature' ) &&
			ExtensionRegistry::getInstance()->isLoaded( 'BetaFeatures' )
		) {
			return;
		}

		// If HSTS is mandatory, do not display the choice
		if ( $this->config->get( 'HSTSForUsers' ) ) {
			return;
		}

		// Add the checkbox in the 'basic informations' section
		$preferences['hsts'] = [
			'type' => 'toggle',
			'label-message' => 'hsts-tog',
			'section' => 'personal/info'
		];

		// Enable this preference only if we are on HTTPS
		if ( $user->getRequest()->detectProtocol() !== 'https' ) {
			$preferences['hsts']['label-message'] = 'hsts-https-tog';
			$preferences['hsts']['disabled'] = true;
		}
	}

	/**
	 * Add the HSTS beta feature
	 *
	 * @param User $user Current user
	 * @param array &$preferences Description of the Beta Features
	 * @return bool|void True or no return value to continue or false to abort
	 *
	 * @todo Add a screenshot (a padlock?)
	 */
	public function onGetBetaFeaturePreferences( $user, &$preferences ) {
		// If HSTS is activated as a Beta Feature, do not add it here
		if ( !$this->config->get( 'HSTSBetaFeature' ) ) {
			return;
		}

		// If HSTS is mandatory, do not display the choice
		if ( $this->config->get( 'HSTSForUsers' ) ) {
			return;
		}

		$preferences['hsts'] = [
			'label-message' => 'hsts-beta-feature-message',
			'desc-message' => 'hsts-beta-feature-description',
			'info-link' => 'https://www.mediawiki.org/wiki/Extension:HSTS',
			'discussion-link' => 'https://www.mediawiki.org/wiki/Extension_talk:HSTS',
			'requirements' => [ 'betafeatures' => [ 'prefershttps' ] ]
		];
	}

	/**
	 * Add the STS header
	 *
	 * @param OutputPage $output Output page object
	 * @param Skin $skin
	 * @return void This hook must not abort, it must return no value
	 */
	public function onBeforePageDisplay( $output, $skin ): void {
		// Check if the user will get STS header
		if (
			$output->getRequest()->detectProtocol() !== 'https'
			|| ( $output->getUser()->isAnon() && !$this->config->get( 'HSTSForAnons' ) )
		) {
			return;
		}

		if (
			$output->getUser()->isRegistered() &&
			!$this->config->get( 'HSTSForUsers' ) &&
			!$this->userOptionsLookup->getOption( $output->getUser(), 'hsts' )
		) {
			return;
		}

		// Compute the max-age property
		$maxage = $this->config->get( 'HSTSMaxAge' );
		if ( is_int( $maxage ) ) {
			$maxage = max( $maxage, 0 );
		} else {
			$maxage = wfTimestamp( TS_UNIX, $maxage );
			if ( $maxage !== false ) {
				$maxage -= wfTimestamp();
			} else {
				wfDebug( '[HSTS] Bad value of the parameter $wgHSTSMaxAge: must be an integer or a date.' );
				return;
			}
			if ( $maxage < 0 ) {
				wfDebug( '[HSTS] Expired date; HSTS has been lost for all users, apart if externally added in the server configuration.' );
				return;
			}
		}

		$header = 'Strict-Transport-Security: max-age=' . $maxage .
			( $this->config->get( 'HSTSIncludeSubdomains' ) ? '; includeSubDomains' : '' );
		// Output the header
		$output->getRequest()->response()->header( $header );
		wfDebug( '[HSTS] ' . $header );
	}
}
