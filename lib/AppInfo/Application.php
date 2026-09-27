<?php

declare(strict_types=1);

namespace OCA\LdapQuotaWriteback\AppInfo;

use OCA\LdapQuotaWriteback\Listener\UserChangedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserChangedEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'ldap_quota_writeback';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(UserChangedEvent::class, UserChangedListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
