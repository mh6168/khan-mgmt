<?php

declare(strict_types=1);

final class AdminApplicationService
{
    public function __construct(
        private readonly FirestoreAdminClient $firestore
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(FirestoreAdminClient::fromEnvironment());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function applications(): array
    {
        $profiles = [];
        foreach ($this->firestore->listUserProfiles() as $profile) {
            $id = (string) ($profile['id'] ?? '');
            if ($id !== '') {
                $profiles[$id] = $profile;
            }
        }

        $applications = [];
        foreach ($this->firestore->listApplications() as $application) {
            $uid = (string) ($application['uid'] ?? $application['id'] ?? '');
            $profile = $profiles[$uid] ?? [];
            $application['_accountName'] = (string) ($profile['name'] ?? '');
            $application['_email'] = (string) ($profile['email'] ?? $application['email'] ?? '');
            $applications[] = $application;
        }

        return $applications;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function application(string $uid): ?array
    {
        $application = $this->firestore->getApplication($uid);
        if ($application === null) {
            return null;
        }

        foreach ($this->firestore->listUserProfiles() as $profile) {
            if (($profile['id'] ?? '') === $uid) {
                $application['_accountName'] = (string) ($profile['name'] ?? '');
                $application['_email'] = (string) ($profile['email'] ?? $application['email'] ?? '');
                break;
            }
        }

        return $application;
    }

    /**
     * @return array{total: int, pending: int, approved: int, rejected: int}
     */
    public function statistics(): array
    {
        $stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
        foreach ($this->applications() as $application) {
            $stats['total']++;
            $status = strtolower((string) ($application['status'] ?? ''));
            if (isset($stats[$status])) {
                $stats[$status]++;
            }
        }

        return $stats;
    }

    public function updateStatus(string $uid, string $status): void
    {
        $this->firestore->updateApplicationStatus($uid, $status);
    }
}
