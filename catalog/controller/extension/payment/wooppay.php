<?php

class ControllerExtensionPaymentWooppay extends Controller
{
	public function index()
	{
		$data['button_confirm'] = $this->language->get('button_confirm');
		$data['redirect'] = $this->config->get('payment_wooppay_payment_place');
		if ($data['redirect'] == 'true') {
			$data['button_confirm_action'] = $this->url->link('extension/payment/wooppay/invoice_with_redirect', '',
				'SSL');
		} else {
			$data['button_confirm_action'] = $this->url->link('extension/payment/wooppay/invoice_without_redirect', '',
				'SSL');
		}
		session_start();
		unset($_SESSION['wooppay']);
		if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/wooppay')) {
			return $this->load->view($this->config->get('config_template') . '/template/payment/wooppay', $data);
		} else {
			return $this->load->view('extension/payment/wooppay', $data);
		}
	}


	public function invoice_without_redirect()
	{
		session_start();
		if (!isset($_SESSION['wooppay'])) {
			$firstStepData = $this->invoice_with_redirect(false);
			$client = $firstStepData['client'];
			$invoice = $firstStepData['invoice'];
			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
			$client->pseudoAuth($order_info['telephone'], $this->config->get('payment_wooppay_link_card'));
			$cards = $client->getCards();
			$_SESSION['wooppay']['client'] = $client;
			$_SESSION['wooppay']['invoice'] = $invoice;
			$data['cards'] = $cards;
			if (!empty($cards)) {
				$cards = $this->load->view('extension/payment/wooppay_cards', $data);
				$_SESSION['wooppay']['cards_view'] = $cards;
				echo $cards;
				die();
			}
		}
		if (!isset($_SESSION['wooppay']['frame_view'])) {
			if (isset($_SESSION['wooppay']['cards_view']) && !isset($_POST['card_id'])) {
				echo $_SESSION['wooppay']['cards_view'];
				die();
			} else {
				$payFromCard = $_SESSION['wooppay']['client']->payFromCard($_SESSION['wooppay']['invoice']->response->invoice_id,
					$_SESSION['wooppay']['invoice']->response->key, $_POST['card_id'] ?? null);
				$frame = "<iframe src='$payFromCard->frame_url' width='600px' height='550px' style='border: none; width: 600px; height: 550px' frameborder='no' align='middle'> </iframe>";
				unset($_SESSION['wooppay']['cards_view']);
				$_SESSION['wooppay']['frame_view'] = $frame;
				$_SESSION['wooppay']['payment_operation'] = $payFromCard->payment_operation;
				echo $frame;
				die();
			}
		} else {
			if (isset($_POST['woop_frame_status'])) {
				switch ($_POST['woop_frame_status']) {
					case 1:
						$receipt = $_SESSION['wooppay']['client']->getReceipt($_SESSION['wooppay']['payment_operation']);
						$answer = json_encode([
							'',
							$receipt,
							$this->url->link('checkout/success')
						]);
						echo $answer;
						break;
					default:
						$data = [
							'woop_frame_error' => $_POST['woop_frame_error'],
							'url' => $this->url->link('checkout/checkout')
						];
						echo json_encode(['', $this->load->view('extension/payment/wooppay_result', $data)]);
				}
				unset($_SESSION['wooppay']);
				die();
			} else {
				echo $_SESSION['wooppay']['frame_view'];
				die();
			}
		}
	}

	public function invoice_with_redirect($redirect = true)
	{
		try {
			$client = new ApiClient($this->config->get('payment_wooppay_url'),
				$this->config->get('payment_wooppay_merchant'), $this->config->get('payment_wooppay_password'));
			$client->auth();
			$this->load->model('extension/payment/wooppay');
			if ($this->model_extension_payment_wooppay->getTransactionRow($this->session->data['order_id'])) {
				$referenceId = trim($this->config->get('payment_wooppay_prefix')) . '_' . $this->session->data['order_id'] . '_' . time();
			} else {
				$referenceId = trim($this->config->get('payment_wooppay_prefix')) . '_' . $this->session->data['order_id'];
			}
			$this->load->model('checkout/order');
			$order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
			if ($this->config->get('payment_wooppay_link_card') == 'on') {
				$linkCard = true;
			} else {
				$linkCard = false;
			}
			$invoice = $client->createInvoice($referenceId,
				(float)$order_info['total'],
				$this->config->get('payment_wooppay_merchant'),
				str_replace('&amp;', '&', $this->url->link('extension/payment/wooppay/callback',
					'order=' . $order_info['order_id'] . '&key=' . md5($order_info['order_id']), 'SSL')),
				$this->url->link('checkout/success'),
				0,
				$order_info['telephone'],
				$linkCard, $this->config->get('payment_wooppay_service'));
			$this->model_checkout_order->addOrderHistory($order_info['order_id'],
				$this->config->get('payment_wooppay_order_processing_status_id'));
			$this->load->model('extension/payment/wooppay');
			$this->model_extension_payment_wooppay->addTransaction([
				'order_id' => $order_info['order_id'],
				'wooppay_transaction_id' => $invoice->response->operation_id
			]);
			if ($redirect) {
				$this->response->redirect($invoice->operation_url);
			} else {
				return [
					'client' => $client,
					'invoice' => $invoice
				];
			}
		} catch (Exception $e) {
			$this->log->write(sprintf('Wooppay exception : %s order id (%s)', $e->getMessage(),
				$this->request->get['order']));
			$this->response->redirect($this->url->link('checkout/failure', '', 'SSL'));
		}
	}

	public function callback()
	{
		if ($this->request->get['key'] == md5($this->request->get['order'])) {
			try {
				$client = new ApiClient($this->config->get('payment_wooppay_url'), $this->config->get('payment_wooppay_merchant'), $this->config->get('payment_wooppay_password'));
				$client->auth();
				$this->load->model('extension/payment/wooppay');
				$operationId = $this->model_extension_payment_wooppay->getTransactionRow($this->request->get['order']);
				if ($operationId) {
					$operationData = $client->getOperationData($operationId['wooppay_transaction_id']);
					if (!isset($operationData[0]->status)) {
						exit;
					}
					if ($operationData[0]->status == 14 || $operationData[0]->status == 19) {
						$this->load->model('checkout/order');
						$this->model_checkout_order->addOrderHistory($this->request->get['order'],
							$this->config->get('payment_wooppay_order_success_status_id'));
					} else {
						$this->log->write(sprintf('Wooppay callback : счет не оплачен (%s) order id (%s)',
							$operationData[0]->status, $this->request->get['order']));
					}
				} else {
					$this->log->write(sprintf('Wooppay order not found : %s order id (%s)',
						$this->request->get['order'], $this->request->get['order']));
				}

			} catch (Exception $e) {
				$this->log->write(sprintf('Wooppay exception : %s order id (%s)', $e->getMessage(),
					$this->request->get['order']));
			}
		} else {
			$this->log->write('Wooppay callback : неверный key или order : ' . print_r($_REQUEST, true));
		}
		echo json_encode(['data' => 1]);
	}
}


class ApiClient
{
	public $apiUrl;
	public $merchantName;
	public $password;

	public $auth;
	public $invoice;

	public $walletType;

	const KZ_COUNTRY_CODE = 1;
	const UZ_COUNTRY_CODE = 860;
	const TJ_COUNTRY_CODE = 762;

	const FINISHED = 14;
	const MERCHANT_WAITING = 19;

	public function __construct($apiUrl, $merchantName, $password)
	{
		$this->apiUrl = $apiUrl;
		$this->merchantName = $merchantName;
		$this->password = $password;
	}

	private function sendPostRequest($methodUrl, $params)
	{
		if ($curl = curl_init()) {
			curl_setopt($curl, CURLOPT_URL, $this->apiUrl . strval($methodUrl));
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			$headers = array('Content-type: application/json', 'language: ru', 'Time-Zone: Asia/Almaty');
			if (isset($this->auth->token)) {
				$headers = array_merge($headers, array("Authorization:" . $this->auth->token . ""));
			} elseif (isset($_SESSION['wooppay']['authToken'])) {
				$headers = array_merge($headers, array("Authorization:" . $_SESSION['wooppay']['authToken'] . ""));
			}
			if (isset($this->invoice->partnerName)) {
				$headers = array_merge($headers, array("partner-name:" . $this->invoice->partnerName . ""));
			}
			curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
			$result = curl_exec($curl);
			if (json_decode($result)) {
				$result = json_decode($result);
			}
			if (curl_getinfo($curl)['http_code'] > 201) {
				if (is_array($result) && isset($result[0]->message)) {
					throw new Exception($result[0]->message);
				} else {
					throw new Exception($result->message);
				}
			}
			curl_close($curl);
			return $result;
		}
	}

	private function sendGetRequest($methodUrl, $params)
	{
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_URL, $this->apiUrl . strval($methodUrl));
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		$headers = array('Content-type: application/json', 'language: ru', 'Time-Zone: Asia/Almaty');
		if (isset($this->auth->token)) {
			$headers = array_merge($headers, array("Authorization:" . $this->auth->token . ""));
		}
		if (isset($this->invoice->partnerName)) {
			$headers = array_merge($headers, array("partner-name:" . $this->invoice->partnerName . ""));
		}
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		$result = curl_exec($curl);
		if (json_decode($result)) {
			$result = json_decode($result);
		}
		if (curl_getinfo($curl)['http_code'] > 201) {
			if (is_array($result) && isset($result[0]->message)) {
				throw new Exception($result[0]->message);
			} else {
				throw new Exception($result->message);
			}
		}
		curl_close($curl);
		return $result;
	}

	public function getOperationData($operation_id)
	{
		return $this->sendPostRequest('/history/transaction/get-operations-data', ['operation_ids' => [$operation_id]]);
	}

	public function getReceipt($operationId)
	{
		$count = 0;
		do {
			$success = true;
			try {
				if ($count < 10) {
					$fileString = $this->sendGetRequest('/history/receipt/pdf/' . $operationId, []);
					return chunk_split(base64_encode($fileString));
				} else {
					$fileString = 'none';
				}
			} catch (Exception $e) {
				sleep(1);
				$count++;
				$success = false;
				continue;
			}
		} while (!$success);
	}

	public function payFromCard($invoiceId, $invoiceKey, $cardId = '')
	{
		$data = [
			'invoice_id' => $invoiceId,
			'key' => $invoiceKey,
		];
		if (isset($cardId) && !empty($cardId)) {
			$data = array_merge($data, ['card_id' => $cardId]);
		}
		return $this->sendPostRequest('/invoice/pay-from-card', $data);
	}

	public function pseudoAuth($phone, $linkCard)
	{
		$data = [
			'login' => $phone
		];
		if ($linkCard == 'on') {
			$data = array_merge($data, ['subject_type' => 5019]);
		} else {
			$this->auth->token = '';
		}
		$this->auth = $this->sendPostRequest('/auth/pseudo', $data);
		$this->walletType = substr($this->auth->login, 0, 1);
	}

	public function getCards()
	{
		if ($this->walletType == 'G') {
			return $this->sendGetRequest('/card', '');
		}
	}

	public function auth()
	{
		try {
			$this->auth = $this->sendPostRequest('/auth',
				['login' => $this->merchantName, 'password' => $this->password]);
		} catch (Exception $e) {
			throw new Exception('Не удалось совершить вход в API, скорее всего неверный логин или пароль');
		}
	}

	private function getInvoiceByCountry()
	{
		try {
			switch ($this->auth->country) {
				case self::KZ_COUNTRY_CODE:
					return new KzInvoice();
				case self::UZ_COUNTRY_CODE:
					return new UzInvoice();
				case self::TJ_COUNTRY_CODE:
					return new TjInvoice();
			}
		} catch (Exception $e) {
			throw new Exception("Инвойс для страны" . $this->auth->country . "не найден!");
		}
	}

	public function createInvoice(
		$referenceId,
		$amount,
		$merchantName,
		$requestUrl,
		$backUrl,
		$cardForbidden,
		$userPhone,
		$linkCard,
		$serviceName = ''
	) {
		try {
			$this->invoice = $this->getInvoiceByCountry();
			$data = [
				'reference_id' => $referenceId,
				'amount' => $amount,
				'merchant_name' => $merchantName,
				'request_url' => ['url' => $requestUrl, 'type' => 'POST'],
				'back_url' => $backUrl,
				'option' => $this->invoice->getOption($linkCard, $userPhone),
				'card_forbidden' => $cardForbidden,
			];
			if (!empty($serviceName)) {
				$data = array_merge($data, ['service_name' => $serviceName]);
			}
			if ($linkCard == true) {
				$data = array_merge($data, ['user_phone' => $userPhone]);
			}
			return $this->sendPostRequest('/invoice/create', $data);
		} catch (Exception $e) {
			throw new Exception('Что то пошло не так при создании инвойса');
		}
	}
}

class KzInvoice
{
	public $partnerName = 'wooppay_kz';

	const OPTION_STANDARD = 0;
	const OPTION_LINKED_CARD = 4;

	public function getOption($linkCard, $userPhone)
	{
		if (empty($userPhone)) {
			return self::OPTION_STANDARD;
		} elseif (!empty($userPhone) && $linkCard) {
			return self::OPTION_LINKED_CARD;
		} else {
			return self::OPTION_STANDARD;
		}
	}
}

class UzInvoice
{
	public $partnerName = 'wooppay_uz';

	const OPTION_STANDARD = 0;
	const OPTION_LINKED_CARD = 4;

	public function getOption($linkCard, $userPhone)
	{
		if (empty($userPhone)) {
			return self::OPTION_STANDARD;
		} elseif (!empty($userPhone) && $linkCard) {
			return self::OPTION_LINKED_CARD;
		} else {
			return self::OPTION_STANDARD;
		}
	}
}

class TjInvoice
{
	public $partnerName = 'wooppay_kz';

	const OPTION_STANDARD = 8;
	const OPTION_LINKED_CARD = 9;

	public function getOption($linkCard, $userPhone)
	{
		if (empty($userPhone)) {
			return self::OPTION_STANDARD;
		} elseif (!empty($userPhone) && $linkCard) {
			return self::OPTION_LINKED_CARD;
		} else {
			return self::OPTION_STANDARD;
		}
	}
}

?>
