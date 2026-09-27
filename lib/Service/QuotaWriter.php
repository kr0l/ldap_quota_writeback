<?php

declare(strict_types=1);

namespace OCA\LdapQuotaWriteback\Service;

use OCP\IAppConfig;
use OCP\IUser;
use OCP\LDAP\ILDAPProviderFactory;
use OCP\Util;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Writes quota changes of LDAP users into the LDAP attribute that user_ldap
 * reads quotas from, so the LDAP sync keeps them instead of reverting them.
 */
class QuotaWriter {
	public function __construct(
		private ILDAPProviderFactory $ldapProviderFactory,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Quota attribute and default quota of the active user_ldap configurations,
	 * or null if quotas are not read from LDAP or the configurations disagree.
	 *
	 * @return array{attribute: string, default: string}|null
	 */
	public function getSettings(): ?array {
		// user_ldap has no public API for these settings, so read its app config
		$values = $this->appConfig->getAllValues('user_ldap', '', true);
		$active = 0;
		$withQuota = 0;
		$attributes = [];
		$defaults = [];
		foreach ($values as $key => $value) {
			$key = (string)$key;
			if (!str_ends_with($key, 'ldap_configuration_active') || (string)$value !== '1') {
				continue;
			}
			$active++;
			$prefix = substr($key, 0, -strlen('ldap_configuration_active'));
			$attribute = trim((string)($values[$prefix . 'ldap_quota_attr'] ?? ''));
			if ($attribute === '') {
				continue;
			}
			$withQuota++;
			$attributes[strtolower($attribute)] = $attribute;
			$defaults[] = trim((string)($values[$prefix . 'ldap_quota_def'] ?? ''));
		}

		if ($active === 0) {
			// Also happens if a future user_ldap stores its settings differently
			$this->logger->warning('No active LDAP configuration found in the user_ldap settings, not writing quotas to LDAP');
			return null;
		}
		if ($withQuota === 0) {
			return null;
		}
		if ($withQuota !== $active || count($attributes) > 1 || count(array_unique($defaults)) > 1) {
			// Several LDAP configurations with different quota settings: guessing
			// could write wrong values, so do nothing.
			$this->logger->error('Active LDAP configurations use different quota settings, not writing quotas to LDAP');
			return null;
		}
		return [
			'attribute' => array_values($attributes)[0],
			'default' => $defaults[0],
		];
	}

	/**
	 * Current raw value of the LDAP quota attribute of a user, null if not set.
	 *
	 * @throws Throwable if LDAP cannot be read
	 */
	public function readLdapQuota(IUser $user): ?string {
		$settings = $this->getSettings();
		if ($settings === null || $user->getBackendClassName() !== 'LDAP') {
			return null;
		}
		$provider = $this->ldapProviderFactory->getLDAPProvider();
		$uid = $user->getUID();
		return $this->read($provider->getLDAPConnection($uid), $provider->getUserDN($uid), $settings['attribute']);
	}

	/**
	 * Makes LDAP match the given Nextcloud quota ("20 GB", "none" or "default").
	 * Never throws, errors are logged.
	 *
	 * @return string what happened, for command output
	 */
	public function write(IUser $user, string $quota): string {
		$uid = $user->getUID();
		try {
			if ($user->getBackendClassName() !== 'LDAP') {
				return 'skipped: not an LDAP user';
			}
			$settings = $this->getSettings();
			if ($settings === null) {
				return 'skipped: user_ldap does not read quotas from LDAP';
			}
			$target = $this->normalize($quota);
			if ($target === null) {
				$this->logger->warning("Not writing invalid quota \"$quota\" of user $uid to LDAP");
				return 'skipped: invalid quota';
			}

			$attribute = $settings['attribute'];
			$provider = $this->ldapProviderFactory->getLDAPProvider();
			$dn = $provider->getUserDN($uid);
			$connection = $provider->getLDAPConnection($uid);
			$current = $this->read($connection, $dn, $attribute);
			$currentQuota = $this->normalize($current);

			if ($target === 'default') {
				// Remove the individual value so the LDAP default quota applies again.
				// user_ldap treats invalid values like missing ones, so those can stay.
				if ($currentQuota === null || $currentQuota === 'default') {
					return 'unchanged: no individual quota in LDAP';
				}
				if (!@ldap_mod_del($connection, $dn, [$attribute => []])) {
					throw new RuntimeException(ldap_error($connection));
				}
				$this->logger->info("Removed LDAP quota of user $uid (was: $current)");
				return "removed $attribute (was: $current)";
			}

			// What user_ldap applies for this user. Quota changes made by the LDAP
			// sync itself always match it, so they never cause a write.
			$effective = $currentQuota ?? $this->normalize($settings['default']);
			if ($effective === $target) {
				return "unchanged: LDAP already results in $target";
			}
			if (!@ldap_mod_replace($connection, $dn, [$attribute => [$target]])) {
				throw new RuntimeException(ldap_error($connection));
			}
			$was = $current ?? 'not set';
			$this->logger->info("Wrote LDAP quota of user $uid: $target (was: $was)");
			return "written $attribute: $target (was: $was)";
		} catch (Throwable $e) {
			$this->logger->error("Could not write quota \"$quota\" of user $uid to LDAP: " . $e->getMessage(), ['exception' => $e]);
			return 'error: ' . $e->getMessage();
		}
	}

	/**
	 * Quota in the form Nextcloud stores it ("10 GB", "none", "default"),
	 * null if empty or invalid (user_ldap then uses the default quota).
	 */
	public function normalize(?string $quota): ?string {
		$quota = trim((string)$quota);
		if ($quota === '') {
			return null;
		}
		if ($quota === 'none' || $quota === 'default') {
			return $quota;
		}
		$bytes = Util::computerFileSize($quota);
		return $bytes === false ? null : Util::humanFileSize($bytes);
	}

	/**
	 * @param \LDAP\Connection $connection
	 */
	private function read($connection, string $dn, string $attribute): ?string {
		$result = @ldap_read($connection, $dn, '(objectClass=*)', [$attribute]);
		if ($result === false) {
			throw new RuntimeException("Could not read $dn: " . ldap_error($connection));
		}
		$entries = ldap_get_entries($connection, $result);
		$value = $entries[0][strtolower($attribute)][0] ?? null;
		return $value === null ? null : (string)$value;
	}
}
