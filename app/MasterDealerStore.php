<?php

declare(strict_types=1);

final class FirestoreTimestampValue
{
    public function __construct(public readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

class MasterDealerStore
{
    public function __construct(
        private readonly FirestoreAdminClient $firestore
    ) {
    }

    public static function fromEnvironment(): self
    {
        return new self(FirestoreAdminClient::fromEnvironment());
    }

    public function beginTransaction(): string
    {
        return $this->firestore->beginTransaction();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function read(string $path, string $transaction): ?array
    {
        return $this->firestore->readTransactionDocument($transaction, $path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function document(string $path): ?array
    {
        return $this->firestore->document($path);
    }

    /**
     * @param list<array<string, mixed>> $writes
     */
    public function commit(string $transaction, array $writes): void
    {
        $this->firestore->commitTransaction($transaction, $writes);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createWrite(string $path, array $data): array
    {
        return [
            'update' => [
                'name' => $this->documentName($path),
                'fields' => $this->encodeFields($data),
            ],
            'currentDocument' => ['exists' => false],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateWrite(string $path, array $data): array
    {
        unset($data['id']);

        return [
            'update' => [
                'name' => $this->documentName($path),
                'fields' => $this->encodeFields($data),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function collection(string $name): array
    {
        return $this->firestore->collection($name);
    }

    public function newId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $values
     */
    private function encodeFields(array $values): array
    {
        $fields = [];
        foreach ($values as $name => $value) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Firestore field name is invalid.');
            }
            $fields[$name] = $this->encodeValue($value);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function encodeValue(mixed $value): array
    {
        if ($value instanceof FirestoreTimestampValue) {
            return ['timestampValue' => $value->value];
        }
        if (is_string($value)) {
            return ['stringValue' => $value];
        }
        if (is_int($value)) {
            return ['integerValue' => (string) $value];
        }
        if (is_float($value) && is_finite($value)) {
            return ['doubleValue' => $value];
        }
        if (is_bool($value)) {
            return ['booleanValue' => $value];
        }
        if ($value === null) {
            return ['nullValue' => null];
        }
        if (is_array($value) && array_is_list($value)) {
            return ['arrayValue' => [
                'values' => array_map(fn (mixed $entry): array => $this->encodeValue($entry), $value),
            ]];
        }
        if (is_array($value)) {
            return ['mapValue' => ['fields' => $this->encodeFields($value)]];
        }

        throw new InvalidArgumentException('Firestore value type is unsupported.');
    }

    private function documentName(string $path): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*(\/[A-Za-z0-9_-]+)+$/', $path) !== 1) {
            throw new InvalidArgumentException('Firestore document path is invalid.');
        }

        $projectId = trim((string) (getenv('FIREBASE_PROJECT_ID') ?: ''));
        if ($projectId === '') {
            throw new InvalidArgumentException('Firebase project is not configured.');
        }

        return 'projects/' . $projectId . '/databases/(default)/documents/' . $path;
    }
}
