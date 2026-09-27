<?php

declare(strict_types=1);

namespace OCA\LdapQuotaWriteback\Listener;

use OCA\LdapQuotaWriteback\Service\QuotaWriter;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\ISubAdmin;
use OCP\IGroupManager;
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
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserChangedEvent || $event->getFeature() !== 'quota') {
			return;
		}
		try {
			$target = $event->getUser();
			$actor = $this->userSession->getUser();
			// Only write back changes an admin or group admin made for someone else.
			// This leaves out the LDAP sync (no session), logins (the user themselves)
			// and apps that set quotas automatically, e.g. SSO attribute mappings.
			if ($actor === null || $actor->getUID() === $target->getUID()) {
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
}
