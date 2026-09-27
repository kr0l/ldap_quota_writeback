<?php

declare(strict_types=1);

namespace OCA\LdapQuotaWriteback\Command;

use OCA\LdapQuotaWriteback\Service\QuotaWriter;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Show extends Command {
	public function __construct(
		private IUserManager $userManager,
		private QuotaWriter $quotaWriter,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ldap-quota:show')
			->setDescription('Shows the quota of a user in Nextcloud and in LDAP')
			->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud user ID');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		$user = $this->userManager->get($uid);
		if ($user === null) {
			$output->writeln("<error>User not found: $uid</error>");
			return 1;
		}

		$output->writeln('User:            ' . $user->getUID() . ' (' . $user->getBackendClassName() . ')');
		$output->writeln('Nextcloud quota: ' . $user->getQuota());

		$settings = $this->quotaWriter->getSettings();
		if ($settings === null) {
			$output->writeln('LDAP quota:      - (user_ldap does not read quotas from LDAP)');
			return 0;
		}
		$default = $settings['default'] !== '' ? $settings['default'] : '-';
		$output->writeln('LDAP attribute:  ' . $settings['attribute'] . " (default: $default)");
		if ($user->getBackendClassName() !== 'LDAP') {
			$output->writeln('LDAP quota:      - (not an LDAP user)');
			return 0;
		}
		try {
			$output->writeln('LDAP quota:      ' . ($this->quotaWriter->readLdapQuota($user) ?? 'not set'));
		} catch (Throwable $e) {
			$output->writeln('<error>Could not read LDAP: ' . $e->getMessage() . '</error>');
			return 1;
		}
		return 0;
	}
}
