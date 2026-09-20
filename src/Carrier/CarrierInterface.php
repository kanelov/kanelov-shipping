<?php
namespace Kanelov\Shipping\Carrier;

defined( 'ABSPATH' ) || exit;

/**
 * Общ договор за куриер. Еконт е първата реализация; BoxNow и други ще следват същия интерфейс,
 * така че чекаутът и админ екраните да не знаят нищо специфично за куриера.
 */
interface CarrierInterface {

	public function id(): string;

	public function label(): string;

	/** @return string[] Подмножество на DeliveryData::TYPE_* */
	public function supported_types(): array;

	/** Проверява данните за достъп. Връща празен масив при успех или списък с грешки. */
	public function test_connection(): array;

	/** Валидира избора на клиента преди поръчка. Връща списък с грешки (празен = OK). */
	public function validate_delivery( DeliveryData $delivery ): array;

	/** Четим текст на адреса/офиса за поръчката и имейлите. */
	public function format_delivery( DeliveryData $delivery ): string;

	/** Изчислява реалната цена на куриера за поръчка (admin), в EUR. */
	public function calculate( \WC_Order $order, array $options = [] ): LabelResult;

	public function create_label( \WC_Order $order, array $options = [] ): LabelResult;

	public function delete_label( \WC_Order $order ): LabelResult;

	public function track( \WC_Order $order ): TrackingResult;
}
