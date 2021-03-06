<?php

use ninja\repositories\AccountRepository;
use ninja\mailers\UserMailer as Mailer;

class AccountController extends \BaseController {

	protected $accountRepo;
	protected $mailer;

	public function __construct(AccountRepository $accountRepo, Mailer $mailer)
	{
		parent::__construct();

		$this->accountRepo = $accountRepo;
		$this->mailer = $mailer;
	}	

	public function getStarted()
	{	
		if (Auth::check())
		{
			return Redirect::to('invoices/create');	
		}

		$user = false;	
		$guestKey = Input::get('guest_key');

		if ($guestKey) 
		{
			//$user = User::where('password', '=', $guestKey)->firstOrFail();
			$user = User::where('password', '=', $guestKey)->first();

			if ($user && $user->registered)
			{
				exit;
			}
		}

		if (!$user)
		{
			$account = new Account;
			$account->ip = Request::getClientIp();
			$account->account_key = str_random(RANDOM_KEY_LENGTH);
			$account->save();
			
			$random = str_random(RANDOM_KEY_LENGTH);

			$user = new User;
			$user->password = $random;
			$user->password_confirmation = $random;			
			$user->username = $random;
			$account->users()->save($user);			
			
			Session::forget(RECENTLY_VIEWED);
		}

		Auth::login($user, true);
		Event::fire('user.login');		
		
		return Redirect::to('invoices/create');		
	}

	public function setTrashVisible($entityType, $visible)
	{
		Session::put('show_trash', $visible == 'true');
		return Redirect::to("{$entityType}s");
	}

	public function getSearchData()
	{
		$data = $this->accountRepo->getSearchData();

		return Response::json($data);
	}

	public function showSection($section = ACCOUNT_DETAILS)  
	{
		if ($section == ACCOUNT_DETAILS)
		{			
			$data = [
				'account' => Account::with('users')->findOrFail(Auth::user()->account_id),
				'countries' => Country::remember(DEFAULT_QUERY_CACHE)->orderBy('name')->get(),
				'sizes' => Size::remember(DEFAULT_QUERY_CACHE)->orderBy('id')->get(),
				'industries' => Industry::remember(DEFAULT_QUERY_CACHE)->orderBy('id')->get(),				
				'timezones' => Timezone::remember(DEFAULT_QUERY_CACHE)->orderBy('location')->get(),
				'dateFormats' => DateFormat::remember(DEFAULT_QUERY_CACHE)->get(),
				'datetimeFormats' => DatetimeFormat::remember(DEFAULT_QUERY_CACHE)->get(),
				'currencies' => Currency::remember(DEFAULT_QUERY_CACHE)->orderBy('name')->get(),
				'languages' => Language::remember(DEFAULT_QUERY_CACHE)->orderBy('name')->get(),
			];

			return View::make('accounts.details', $data);
		}
		else if ($section == ACCOUNT_PAYMENTS)
		{
			$account = Account::with('account_gateways')->findOrFail(Auth::user()->account_id);
			$accountGateway = null;
			$config = null;

			if (count($account->account_gateways) > 0)
			{
				$accountGateway = $account->account_gateways[0];
				$config = $accountGateway->config;
			}			

			$data = [
				'account' => $account,
				'accountGateway' => $accountGateway,
				'config' => json_decode($config),
				'gateways' => Gateway::remember(DEFAULT_QUERY_CACHE)->get(),
			];
			
			foreach ($data['gateways'] as $gateway)
			{
				$gateway->fields = Omnipay::create($gateway->provider)->getDefaultParameters();				

				if ($accountGateway && $accountGateway->gateway_id == $gateway->id)
				{
					$accountGateway->fields = $gateway->fields;						
				}
			}	

			return View::make('accounts.payments', $data);
		}
		else if ($section == ACCOUNT_NOTIFICATIONS)
		{
			$data = [
				'account' => Account::with('users')->findOrFail(Auth::user()->account_id),
			];

			return View::make('accounts.notifications', $data);
		}
		else if ($section == ACCOUNT_IMPORT_EXPORT)
		{
			return View::make('accounts.import_export');	
		}	
	}

	public function doSection($section = ACCOUNT_DETAILS)
	{
		if ($section == ACCOUNT_DETAILS)
		{
			return AccountController::saveDetails();
		}
		else if ($section == ACCOUNT_PAYMENTS)
		{
			return AccountController::savePayments();
		}
		else if ($section == ACCOUNT_IMPORT_EXPORT)
		{
			return AccountController::importFile();
		}
		else if ($section == ACCOUNT_MAP)
		{
			return AccountController::mapFile();
		}
		else if ($section == ACCOUNT_NOTIFICATIONS)
		{
			return AccountController::saveNotifications();
		}		
		else if ($section == ACCOUNT_EXPORT)
		{
			return AccountController::export();
		}		
	}

	private function export()
	{
		$output = fopen('php://output','w') or Utils::fatalError();
		header('Content-Type:application/csv'); 
		header('Content-Disposition:attachment;filename=export.csv');
		
		$clients = Client::scope()->get();
		AccountController::exportData($output, $clients->toArray());

		$contacts = Contact::scope()->get();
		AccountController::exportData($output, $contacts->toArray());

		$invoices = Invoice::scope()->get();
		AccountController::exportData($output, $invoices->toArray());

		$invoiceItems = InvoiceItem::scope()->get();
		AccountController::exportData($output, $invoiceItems->toArray());

		$payments = Payment::scope()->get();
		AccountController::exportData($output, $payments->toArray());

		$credits = Credit::scope()->get();
		AccountController::exportData($output, $credits->toArray());

		fclose($output);
		exit;
	}

	private function exportData($output, $data)
	{
		if (count($data) > 0)
		{
			fputcsv($output, array_keys($data[0]));
		}

		foreach($data as $record) 
		{
		    fputcsv($output, $record);
		}

		fwrite($output, "\n");
	}

	private function importFile()
	{
		$data = Session::get('data');
		Session::forget('data');

		$map = Input::get('map');
		$count = 0;
		$hasHeaders = Input::get('header_checkbox');
		
		$countries = Country::remember(DEFAULT_QUERY_CACHE)->get();
		$countryMap = [];

		foreach ($countries as $country) 
		{
			$countryMap[strtolower($country->name)] = $country->id;
		}		

		foreach ($data as $row)
		{
			if ($hasHeaders)
			{
				$hasHeaders = false;
				continue;
			}

			$client = Client::createNew();		
			$contact = Contact::createNew();
			$contact->is_primary = true;
			$count++;

			foreach ($row as $index => $value)
			{
				$field = $map[$index];
				$value = trim($value);

				if ($field == Client::$fieldName && !$client->name)
				{
					$client->name = $value;
				}			
				else if ($field == Client::$fieldPhone && !$client->work_phone)
				{
					$client->work_phone = $value;
				}
				else if ($field == Client::$fieldAddress1 && !$client->address1)
				{
					$client->address1 = $value;
				}
				else if ($field == Client::$fieldAddress2 && !$client->address2)
				{
					$client->address2 = $value;
				}
				else if ($field == Client::$fieldCity && !$client->city)
				{
					$client->city = $value;
				}
				else if ($field == Client::$fieldState && !$client->state)
				{
					$client->state = $value;
				}
				else if ($field == Client::$fieldPostalCode && !$client->postal_code)
				{
					$client->postal_code = $value;
				}
				else if ($field == Client::$fieldCountry && !$client->country_id)
				{
					$value = strtolower($value);
					$client->country_id = isset($countryMap[$value]) ? $countryMap[$value] : null;
				}
				else if ($field == Client::$fieldNotes && !$client->private_notes)
				{
					$client->private_notes = $value;
				}
				else if ($field == Contact::$fieldFirstName && !$contact->first_name)
				{
					$contact->first_name = $value;
				}
				else if ($field == Contact::$fieldLastName && !$contact->last_name)
				{
					$contact->last_name = $value;
				}
				else if ($field == Contact::$fieldPhone && !$contact->phone)
				{
					$contact->phone = $value;
				}
				else if ($field == Contact::$fieldEmail && !$contact->email)
				{
					$contact->email = strtolower($value);
				}				
			}

			$client->save();
			$client->contacts()->save($contact);		
			Activity::createClient($client);
		}

		$message = Utils::pluralize('created_client', $count);
		Session::flash('message', $message);
		return Redirect::to('clients');
	}

	private function mapFile()
	{		
		$file = Input::file('file');

		if ($file == null)
		{
			Session::flash('error', trans('texts.select_file'));
			return Redirect::to('company/import_export');			
		}

		$name = $file->getRealPath();

		require_once(app_path().'/includes/parsecsv.lib.php');
		$csv = new parseCSV();
		$csv->heading = false;
		$csv->auto($name);
		
		if (count($csv->data) + Client::scope()->count() > MAX_NUM_CLIENTS)
		{
			$message = Utils::pluralize('limit_clients', MAX_NUM_CLIENTS);
			Session::flash('error', $message);
			return Redirect::to('company/import_export');
		}

		Session::put('data', $csv->data);

		$headers = false;
		$hasHeaders = false;
		$mapped = array();
		$columns = array('',
			Client::$fieldName,
			Client::$fieldPhone,
			Client::$fieldAddress1,
			Client::$fieldAddress2,
			Client::$fieldCity,
			Client::$fieldState,
			Client::$fieldPostalCode,
			Client::$fieldCountry,
			Client::$fieldNotes,
			Contact::$fieldFirstName,
			Contact::$fieldLastName,
			Contact::$fieldPhone,
			Contact::$fieldEmail
		);

		if (count($csv->data) > 0) 
		{
			$headers = $csv->data[0];
			foreach ($headers as $title) 
			{
				if (strpos(strtolower($title),'name') > 0)
				{
					$hasHeaders = true;
					break;
				}
			}

			for ($i=0; $i<count($headers); $i++)
			{
				$title = strtolower($headers[$i]);
				$mapped[$i] = '';

				if ($hasHeaders)
				{
					$map = array(
						'first' => Contact::$fieldFirstName,
						'last' => Contact::$fieldLastName,
						'email' => Contact::$fieldEmail,
						'mobile' => Contact::$fieldPhone,
						'phone' => Client::$fieldPhone,
						'name|organization' => Client::$fieldName,
						'street|address|address1' => Client::$fieldAddress1,	
						'street2|address2' => Client::$fieldAddress2,						
						'city' => Client::$fieldCity,
						'state|province' => Client::$fieldState,
						'zip|postal|code' => Client::$fieldPostalCode,
						'country' => Client::$fieldCountry,
						'note' => Client::$fieldNotes,
					);

					foreach ($map as $search => $column)
					{
						foreach(explode("|", $search) as $string)
						{
							if (strpos($title, 'sec') === 0)
							{
								continue;
							}

							if (strpos($title, $string) !== false)
							{
								$mapped[$i] = $column;
								break(2);
							}
						}
					}
				}
			}
		}

		$data = array(
			'data' => $csv->data, 
			'headers' => $headers,
			'hasHeaders' => $hasHeaders,
			'columns' => $columns,
			'mapped' => $mapped
		);

		return View::make('accounts.import_map', $data);
	}

	private function saveNotifications()
	{
		$account = Account::findOrFail(Auth::user()->account_id);			
		$account->invoice_terms = Input::get('invoice_terms');
		$account->email_footer = Input::get('email_footer');
		$account->save();

		$user = Auth::user();
		$user->notify_sent = Input::get('notify_sent');
		$user->notify_viewed = Input::get('notify_viewed');
		$user->notify_paid = Input::get('notify_paid');
		$user->save();
		
		Session::flash('message', trans('texts.updated_settings'));
		return Redirect::to('company/notifications');
	}

	private function savePayments()
	{
		$rules = array();

		if ($gatewayId = Input::get('gateway_id')) 
		{
			$gateway = Gateway::findOrFail($gatewayId);
			$fields = Omnipay::create($gateway->provider)->getDefaultParameters();
			
			foreach ($fields as $field => $details)
			{
				if (!in_array($field, ['testMode', 'developerMode', 'headerImageUrl', 'solutionType', 'landingPage', 'brandName']))
				{
					$rules[$gateway->id.'_'.$field] = 'required';
				}				
			}			
		}
		
		$validator = Validator::make(Input::all(), $rules);

		if ($validator->fails()) 
		{
			return Redirect::to('company/payments')
				->withErrors($validator)
				->withInput();
		} 
		else 
		{
			$account = Account::findOrFail(Auth::user()->account_id);						
			$account->account_gateways()->delete();

			if ($gatewayId) 
			{
				$accountGateway = AccountGateway::createNew();
				$accountGateway->gateway_id = $gatewayId;

				$config = new stdClass;
				foreach ($fields as $field => $details)
				{
					$config->$field = trim(Input::get($gateway->id.'_'.$field));
				}			
				
				$accountGateway->config = json_encode($config);
				$account->account_gateways()->save($accountGateway);
			}

			Session::flash('message', trans('texts.updated_settings'));
			return Redirect::to('company/payments');
		}				
	}

	private function saveDetails()
	{
		$rules = array(
			'name' => 'required',
			'email' => 'email|required|unique:users,email,' . Auth::user()->id . ',id'
		);

		$validator = Validator::make(Input::all(), $rules);

		if ($validator->fails()) 
		{
			return Redirect::to('company/details')
				->withErrors($validator)
				->withInput();
		} 
		else 
		{
			$account = Auth::user()->account;
			$account->name = trim(Input::get('name'));
			$account->work_email = trim(Input::get('work_email'));
			$account->work_phone = trim(Input::get('work_phone'));
			$account->address1 = trim(Input::get('address1'));
			$account->address2 = trim(Input::get('address2'));
			$account->city = trim(Input::get('city'));
			$account->state = trim(Input::get('state'));
			$account->postal_code = trim(Input::get('postal_code'));
			$account->country_id = Input::get('country_id') ? Input::get('country_id') : null;			
			$account->size_id = Input::get('size_id') ? Input::get('size_id') : null;
			$account->industry_id = Input::get('industry_id') ? Input::get('industry_id') : null;
			$account->timezone_id = Input::get('timezone_id') ? Input::get('timezone_id') : null;
			$account->date_format_id = Input::get('date_format_id') ? Input::get('date_format_id') : null;
			$account->datetime_format_id = Input::get('datetime_format_id') ? Input::get('datetime_format_id') : null;
			$account->currency_id = Input::get('currency_id') ? Input::get('currency_id') : 1; // US Dollar
			$account->language_id = Input::get('language_id') ? Input::get('language_id') : 1; // English
			$account->save();

			$user = Auth::user();
			$user->first_name = trim(Input::get('first_name'));
			$user->last_name = trim(Input::get('last_name'));
			$user->username = trim(Input::get('email'));
			$user->email = trim(strtolower(Input::get('email')));
			$user->phone = trim(Input::get('phone'));				
			$user->save();

			/* Logo image file */
			if ($file = Input::file('logo'))
			{
				$path = Input::file('logo')->getRealPath();
				File::delete('logo/' . $account->account_key . '.jpg');				
				Image::make($path)->resize(null, 120, true, false)->save('logo/' . $account->account_key . '.jpg');				
			}

			Event::fire('user.refresh');

			Session::flash('message', trans('texts.updated_settings'));
			return Redirect::to('company/details');
		}
	}

	public function removeLogo() {

		File::delete('logo/' . Auth::user()->account->account_key . '.jpg');

		Session::flash('message', trans('texts.removed_logo'));
		return Redirect::to('company/details');		
	}

	public function checkEmail()
	{		
		$email = User::withTrashed()->where('email', '=', Input::get('email'))->where('id', '<>', Auth::user()->id)->first();

		if ($email) 
		{
			return "taken";
		} 
		else 
		{
			return "available";
		}
	}

	public function submitSignup()
	{
		$rules = array(
			'new_first_name' => 'required',
			'new_last_name' => 'required',
			'new_password' => 'required|min:6',
			'new_email' => 'email|required|unique:users,email,' . Auth::user()->id . ',id'
		);

		$validator = Validator::make(Input::all(), $rules);

		if ($validator->fails()) 
		{
			return '';
		} 

		$user = Auth::user();
		$user->first_name = trim(Input::get('new_first_name'));
		$user->last_name = trim(Input::get('new_last_name'));
		$user->email = trim(strtolower(Input::get('new_email')));
		$user->username = $user->email;
		$user->password = trim(Input::get('new_password'));
		$user->password_confirmation = trim(Input::get('new_password'));
		$user->registered = true;
		$user->amend();

		$this->mailer->sendConfirmation($user);

		$activities = Activity::scope()->get();
		foreach ($activities as $activity) 
		{
			$activity->message = str_replace('Guest', $user->getFullName(), $activity->message);
			$activity->save();
		}

		return "{$user->first_name} {$user->last_name}";
	}
}