<?php

declare(strict_types=1);

namespace OCA\LdapQuotaWriteback\Listener;

use OCA\LdapQuotaWriteback\Service\QuotaWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\User\Events\UserChangedEvent;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @template-implements IEventListener<Event>
 */
class UserChangedListener implements IEventListener {
	public function __construct(
		private QuotaWriter $quotaWriter,
		private IUserSession $userSession,
		private IGroupManager $groupManager,
		private ISubAdmin $subAdmin,
		private IRequest $request,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserChangedEvent || $event->getFeature() !== 'quota') {
			return;
		}
		try {
			// Only write back changes made in the user management. This leaves out
			// the LDAP sync (cron), logins and apps that set quotas automatically,
			// e.g. SSO attribute mappings.
			if (!$this->isUserManagementRequest()) {
				return;
			}
			$target = $event->getUser();
			$actor = $this->userSession->getUser();
			if ($actor === null) {
				return;
			}
			if (!$this->groupManager->isAdmin($actor->getUID()) && !$this->subAdmin->isUserAccessible($actor, $target)) {
				return;
			}
			$this->quotaWriter->write($target, (string)$event->getValue());
		} catch (Throwable $e) {
			// Never break the quota change itself
			$this->logger->error('LDAP quota writeback failed: ' . $e->getMessage(), ['exception' => $e]);
		}
	}

	/**
	 * The user management edits users through the provisioning API:
	 * /ocs/v2.php/cloud/users/{userId} (PUT, or PATCH since Nextcloud 34).
	 */
	private function isUserManagementRequest(): bool {
		if (PHP_SAPI === 'cli') {
			return false;
		}
		try {
			$path = $this->request->getPathInfo();
		} catch (Throwable) {
			return false;
		}
		return is_string($path) && preg_match('#^/cloud/users(/|$)#', $path) === 1;
	}
}
