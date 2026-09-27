<?php

declare(strict_types=1);

namespace OCA\LdapQuotaWriteback\Command;

use InvalidArgumentException;
use OCA\LdapQuotaWriteback\Service\QuotaWriter;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class Set extends Command {
	public function __construct(
		private IUserManager $userManager,
		private QuotaWriter $quotaWriter,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('ldap-quota:set')
			->setDescription('Sets the quota of a user in Nextcloud and writes it to LDAP')
			->addArgument('uid', InputArgument::REQUIRED, 'Nextcloud user ID')
			->addArgument('quota', InputArgument::REQUIRED, 'e.g. "20 GB", "none" (unlimited) or "default"');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$uid = (string)$input->getArgument('uid');
		$user = $this->userManager->get($uid);
		if ($user === null) {
			$output->writeln("<error>User not found: $uid</error>");
			return 1;
		}

		$quota = trim((string)$input->getArgument('quota'));
		try {
			$user->setQuota($quota);
		} catch (InvalidArgumentException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		// The listener ignores the command line (no logged-in admin), so write here
		$result = $this->quotaWriter->write($user, (string)$this->quotaWriter->normalize($quota));
		$output->writeln('Nextcloud quota: ' . $user->getQuota());
		if (str_starts_with($result, 'error') || str_starts_with($result, 'skipped')) {
			$output->writeln('LDAP:            ' . $result);
			return str_starts_with($result, 'error') ? 1 : 0;
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
