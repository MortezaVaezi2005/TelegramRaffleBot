<?php declare(strict_types=1);

namespace Amp\Http;

abstract class HttpResponse extends HttpMessage
{
    private int $status;

    private string $reason;

    public function __construct(
        int $status,
        ?string $reason = null,
    ) {
        $this->validateStatusCode($status, $reason);
    }

    /**
     * Retrieve the response's three-digit HTTP status code.
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * Retrieve the response's (possibly empty) reason phrase.
     */
    public function getReason(): string
    {
        return $this->reason;
    }

    protected function setStatus(int $status, ?string $reason = null): void
    {
        $this->validateStatusCode($status, $reason);
    }

    private function validateStatusCode(int $status, ?string $reason): void
    {
        if ($status < 100 || $status > 599) {
            throw new \ValueError(
                'Invalid status code. Must be an integer between 100 and 599, inclusive.'
            );
        }

        $this->status = $status;
        $this->reason = $reason ?? HttpStatus::getReason($status);
    }

    /**
     * Response has a status code between 100 and 199.
     * @see HttpStatus::isInformational()
     */
    public function isInformational(): bool
    {
        return HttpStatus::isInformational($this->status);
    }

    /**
     * Response has a status code between 200 and 299.
     * @see HttpStatus::isSuccessful()
     */
    public function isSuccessful(): bool
    {
        return HttpStatus::isSuccessful($this->status);
    }

    /**
     * Response has a status code between 300 and 399.
     * @see HttpStatus::isRedirect()
     */
    public function isRedirect(): bool
    {
        return HttpStatus::isRedirect($this->status);
    }

    /**
     * Response has a status code between 400 and 499.
     * @see HttpStatus::isClientError()
     */
    public function isClientError(): bool
    {
        return HttpStatus::isClientError($this->status);
    }

    /**
     * Response has a status code between 500 and 599.
     * @see HttpStatus::isServerError()
     */
    public function isServerError(): bool
    {
        return HttpStatus::isServerError($this->status);
    }
}
