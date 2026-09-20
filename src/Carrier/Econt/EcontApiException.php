<?php
namespace Kanelov\Shipping\Carrier\Econt;

class EcontApiException extends \RuntimeException {

	/** @var string[] */
	private array $messages;

	public function __construct( array $messages, string $type = '' ) {
		$this->messages = array_values( array_filter( array_map( 'strval', $messages ) ) );
		parent::__construct( implode( ' | ', $this->messages ) ?: 'Econt API error', 0 );
		$this->type = $type;
	}

	public string $type = '';

	/** @return string[] */
	public function messages(): array {
		return $this->messages;
	}
}
