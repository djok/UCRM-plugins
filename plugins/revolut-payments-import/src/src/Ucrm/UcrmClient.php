<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

interface UcrmClient
{
    /**
     * @param array<string,scalar> $params
     * @return array<mixed>
     */
    public function get(string $endpoint, array $params = []): array;

    /**
     * @param array<string,mixed> $data
     * @return array<mixed>
     */
    public function post(string $endpoint, array $data): array;

    /**
     * @param array<string,mixed> $data
     * @return array<mixed>
     */
    public function patch(string $endpoint, array $data): array;
}
