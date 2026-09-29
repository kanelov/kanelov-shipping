<?php
namespace Kanelov\Shipping\Carrier\BoxNow;

final class BoxNowApiException extends \RuntimeException {

	/** @param string[] $messages */
	public function __construct( private array $messages, private string $type = '' ) {
		parent::__construct( implode( '; ', $messages ) );
	}

	/** @return string[] */
	public function messages(): array {
		return $this->messages;
	}

	/** Код на грешката от Box Now (напр. P410) или HTTP код. */
	public function type(): string {
		return $this->type;
	}
}
