<?php
declare(strict_types=1);

namespace RevolutPaymentsImport\Ucrm;

use Ubnt\UcrmPluginSdk\Service\UcrmApi;

final class SdkUcrmClient implements UcrmClient
{
    public function __construct(private readonly UcrmApi $api)
    {
    }

    public static function create(): self
    {
        return new self(UcrmApi::create());
    }

    public function get(string $endpoint, array $params = []): array
    {
        $result = $this->api->get($endpoint, $params);

        return is_array($result) ? $result : [];
    }

    public function post(string $endpoint, array $data): array
    {
        $result = $this->api->post($endpoint, $data);

        return is_array($result) ? $result : [];
    }

    public function patch(string $endpoint, array $data): array
    {
        $result = $this->api->patch($endpoint, $data);

        return is_array($result) ? $result : [];
    }
}
