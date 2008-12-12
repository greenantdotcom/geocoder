<?php

require_once( '/httpd/apps/coretools/lib/ZoneResolver.php' );

date_default_timezone_set('America/Los_Angeles');

define( 'THIS_REQUEST', 'req'.mt_rand( 888888, 9999999 ) );

class Geocoder
{
	protected $responses;         // Internally populated. Stack of each named geocoder response.

	protected $inputAddress;      // Passed to the constructor. Never changes.

	protected $geocoderResultId;  // Internally populated.

	protected $status;            // Internally populated.

	protected $createdAt;         // Internally populated.

	protected $updatedAt;         // Internally populated.

	protected $dataSource;        // Internally populated. Where the data came from.

	protected $responseCode;      // Internally populated. success or failure

	protected $responseMessage;   // Internally populated. Resaon for responseCode 'failure'

	protected $skipCache = false; // Tells the geocoder to go directly to google with skipCache() method.

	protected $useMysql  = false; // When skipping cache, still use mysql

	const EXPIRE_TIME = 15552000; // How long items are valid for

	const OLDEST_VALID = 1224538789; // The oldest item trusted. Set to time() if you do not trust the cache.

#	const MAX_PER_DAY = 15000;    // Number of times we can hit google per day
	const MAX_PER_DAY = 45000;    // Number of times we can hit google per day

#	const MAX_PER_HOUR = 1000;    // Number of times we can hit google per hour
	const MAX_PER_HOUR = 10000;    // Number of times we can hit google per hour

#	const MAX_PER_MINUTE = 50;    // Number of times we can hit google per minute
	const MAX_PER_MINUTE = 250;    // Number of times we can hit google per minute

	/**
	 * Create a new Geocoder object
	 *
	 * @param mixed $inputAddress
	 */
	public function __construct($inputAddress= '')
	{
		$this->setInputAddress($inputAddress);
	}

	/**
	 * Run the algorithm on the inputAddress passed
	 * to the constructor
	 */
	public function execute()
	{
		$status = $this->doExecute();

		if($this->getResponseCode() == 'failure')
		{
			return;
		}

		if($this->isExpired())
		{
			$this->refreshExpired();
		}

		// Refresh sets skipCache
		if($this->skipCache or $status == 2)
		{
			$this->generateCache();
		}
		elseif($status == 1)
		{
			// Hit mysql, create memcache(s)
			$this->generateQuickCache();
		}

		// Even if all sources fail it will go to google.
		// If google fails it will set 'failure' so...
		if($this->getResponseCode() != 'failure')
		{
			$this->responseCode = 'success';
		}
	}

	/**
	 * Wrapper for execute
	 *
	 * @return int status code
	 */
	private function doExecute()
	{
#		$fp		=	fopen( '/tmp/geocoder.log', 'a' );
		
#		fwrite( $fp, "Searching for <{$this->getAddressHash()}>...\n" );
		
		if(!$this->skipCache and !$this->useMysql and $this->populateFromMemcacheDb())
		{
#			fwrite( $fp, "...memcachedDB = yes\n" );
			
			$this->dataSource = 'memcacheDB';

			return 0;
		}
		
#		fwrite( $fp, "...memcachedDB = no\n" );
		
		// Try the database if we set a lookup connection
		if((!$this->skipCache or $this->useMysql) and $this->populateFromDatabase())
		{
#			fwrite( $fp, "...mysql = yes\n" );
			
			$this->dataSource = 'mysql';

			return 1;
		}
		
#		fwrite( $fp, "...mysql = no\n" );

		$this->dataSource = 'external';
		
#		fwrite( $fp, "searching external..." );
		
		$this->populateFromGoogle();

#		fwrite( $fp, "...done\n" );
		
		return 2;
	}

	/**
	 * Attempts to populate the response via memcacheDB
	 *
	 * @return boolean
	 */
	private function populateFromMemcacheDb()
	{
		try
		{
			if($this->getMemcacheDbConnection())
			{
				$data = $this->getMemcacheDbConnection()->get($this->getAddressHash());
				
				if(strlen($data))
				{
					$data = unserialize($data);

					$this->populate($data);

					return true;
				}
				else
				{
					/*
					$fp 	= fopen( '/tmp/memcache_issues.log', 'a' );
					fwrite( $fp, "Search for <{$this->getAddressHash()}> failed\n" );
					fwrite( $fp, "one more time...\n" );
					fwrite( $fp, print_r( $this->getMemcacheDbConnection()->get($this->getAddressHash() ), true ) );
					fclose( $fp );
					*/
				}
				
			}
		}
		catch(Exception $e)
		{
			$this->logError($e);
		}

		return false;
	}

	/**
	 * Lookup for a chached lookup in the database
	 * TODO: Build this
	 *
	 * @return true or false
	 */
	private function populateFromDatabase()
	{
		try
		{
			if($this->getConnection())
			{

				$sql = "select
							gr.id as geocoder_result_id,
							gr.status,
							gr.address_hash,
							gr.created_at,
							gr.updated_at,
							gra.id as geocoder_result_address_id,
							gra.address,
							gra.city,
							gra.state,
							gra.county,
							gra.country,
							gra.zip,
							gra.latitude,
							gra.longitude,
							gra.score
								from geocoder_results gr
									left join geocoder_result_addresses gra on gr.id=gra.geocoder_result_id
										where gr.address_hash = ?";

				$bindings = array($this->getAddressHash());
				
				$stmt = $this->getConnection()->prepare($sql);
				
				$queryStatus	=	$stmt->execute($bindings);
				
				if( !$queryStatus )
				{
					/*
					$fp 	= fopen( '/tmp/mysql_issues.log', 'a' );
					fwrite( $fp, "Search for <{$this->getAddressHash()}> failed\n" );
					fwrite( $fp, print_r( $bindings, true ) );
					fwrite( $fp, print_r( $stmt->errorInfo(), true ) );
					fclose( $fp );
					*/
				}
				
				$location = $stmt->fetchAll(PDO::FETCH_ASSOC);
				
				$stmt->closeCursor();
				
				unset($stmt);
				
				if(!is_array($location) or !count($location))
				{
					return false;
				}
				
				$this->populate($location);
			}
		}
		catch(Exception $e)
		{
			$this->logError($e);

			return false;
		}

		return true;
	}

	/**
	 * Calls google
	 *
	 * @return boolean
	 */
	private function populateFromGoogle()
	{
		$canHitGoogle	=	$this->canHitGoogle();
		
		if(!$canHitGoogle['status'])
		{
			$this->responseCode		=	'failure';
			$this->dataSource		=	'throttle';
			$this->responseMessage	=	$canHitGoogle['reason'];

			$this->logError($this->responseMessage);

			return;
		}

		try
		{
			$endpoints	=	array
							(
#								'http://10.50.8.14/~markhers/google.php',
								'http://green-ant.com/plain/google.php',
								'http://maps.google.com/maps/geo',
							);
			
			$baseURL	=	$endpoints[array_rand( $endpoints, 1 )];
			
			#$url = $baseURL.'?q=' . urlencode($this->getInputAddress()) . '&output=json&key=' . $this->getGoogleKey();
			$url = $baseURL.'?q=' . urlencode($this->getInputAddress()) . '&output=json';
			//$url = 'http://communitysoftwarelab.com/files/google.php?q=' . urlencode($this->getInputAddress()) . '&output=json';
			
			$ch = curl_init();
			
			curl_setopt_array( $ch, array
									(
										CURLOPT_URL				=>	$url,
										CURLOPT_HEADER			=>	0,
										CURLOPT_FOLLOWLOCATION	=>	1,
										CURLOPT_RETURNTRANSFER	=>	1,
										CURLOPT_BUFFERSIZE		=>	16*1024,
									)
			);
			
			$data = curl_exec($ch);
			curl_close($ch);
			
			$data = utf8_encode($data);
			
			$data = json_decode($data);
			
			// Prevent a save on 620 - G_GEO_TOO_MANY_QUERIES
			if($data->Status->code == '620')
			{
				$this->responseCode = 'failure';

				$this->responseMessage = "Google returned status code: {$data->Status->code}";

				$this->logError($this->responseMessage);

				return;
			}

			if(!isset($data->Placemark))
			{
				return;
			}

			// This is how google offers suggestions ('420 high st, salem, or')
			foreach($data->Placemark as $location)
			{
				$score = @$location->AddressDetails->Accuracy;

				switch($score)
				{
					case 9:
					case 8:
						$score = 100;
						break;
					case 7:
						$score = 85;
						break;
					case 6:
						$score = 70;
						break;
					case 5:
						$score = 55;
						break;
					case 4:
						$score = 40;
						break;
					case 3:
						$score = 25;
						break;
					case 2:
						$score = 10;
						break;
					default:
						$score = 0;
						break;
				}

				//////////////////////
				// FIND THE ADDRESS //
				//////////////////////

				$address = @$location->AddressDetails->Country->AdministrativeArea->Locality->Thoroughfare->ThoroughfareName;

				if(!$address)
				{
					$address = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->Locality->DependentLocality->Thoroughfare->ThoroughfareName;
				}

				if(!$address)
				{
					$address = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->Locality->Thoroughfare->ThoroughfareName;
				}

				if(!$address)
				{
					$address = @$location->AddressDetails->Country->AdministrativeArea->Thoroughfare->ThoroughfareName;
				}

				///////////////////
				// FIND THE CITY //
				/////////////////

				$city = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->Locality->LocalityName;

				if(!$city)
				{
					$city = @$location->AddressDetails->Country->AdministrativeArea->Locality->LocalityName;
				}

				if(!$city)
				{
					$city = @$location->AddressDetails->Country->Premise->PremiseName;
				}

				/////////////////////
				// FIND THE COUNTY //
				/////////////////////

				$county  = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeAreaName;

				if(!$county)
				{
					$county  = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->SubAdministrativeAreaName;
				}

				/////////////////////
				// FIND THE COUNTRY //
				/////////////////////

				$country = @$location->AddressDetails->Country->CountryNameCode;

				////////////////////
				// FIND THE STATE //
				////////////////////

				$state   = @$location->AddressDetails->Country->AdministrativeArea->AdministrativeAreaName;

				if(!$state)
				{
					$state = @$location->AddressDetails->Country->AdministrativeArea->AdministrativeAreaName;
				}

				if(!$state)
				{
					$state  = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->SubAdministrativeAreaName;
				}

				// We want 'CA' not 'C.A.'
				if($country == 'US' and strlen($state) == '4')
				{
					$state = str_replace('.', '', $state);
				}

				//////////////////
				// FIND THE ZIP //
				//////////////////

				$zip  = @$location->AddressDetails->Country->AdministrativeArea->Locality->PostalCode->PostalCodeNumber;

				if(!$zip)
				{
					$zip  = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->Locality->DependentLocality->PostalCode->PostalCodeNumber;
				}

				if(!$zip)
				{
					$zip  = @$location->AddressDetails->Country->AdministrativeArea->SubAdministrativeArea->Locality->PostalCode->PostalCodeNumber;
				}

				if(!$zip)
				{
					$zip  = @$location->AddressDetails->Country->AdministrativeArea->Locality->DependentLocality->PostalCode->PostalCodeNumber;
				}

				if(!$zip)
				{
					$zip  = @$location->AddressDetails->Country->AdministrativeArea->PostalCode->PostalCodeNumber;
				}

				/////////////////////////
				// PUT IT ALL TOGETHER //
				/////////////////////////

				$response = array
				(
					'address' => $address,
					'city'    => $city,
					'state'   => $state,
					'county'  => $county,
					'country' => $country,
					'zip'     => $zip,
				);

				$response['longitude'] = @$location->Point->coordinates[0];

				$response['latitude'] = @$location->Point->coordinates[1];

				$response['score'] = $score;

				$response['status'] = ((count($data->Placemark)) > 1) ? 'suggestions' : 'match';

				$this->addResponse($response);
			}
		}
		catch(Exception $e)
		{

			$this->responseCode = 'failure';

			$this->responseMessage = "Google lookup failed";

			$this->logError($this->responseMessage . ': ' . $e);
		}
	}

	/**
	 * Checks if we are under a limit and incriments counts
	 *
	 * @return boolean
	 */
	private function canHitGoogle()
	{
		if(!$this->getMemcacheDbConnection())
		{
			if($this->testConnectionParam())
			{
				return array( 'status' => true );
			}

			return array( 'status' => false, 'reason' => 'Unable to assess Google hit limit - memcachedb inaccessible' );
		}

		$limits = $this->getMemcacheDbConnection()->get(array('GOOGLE_MAX_PER_DAY', 'GOOGLE_MAX_PER_HOUR', 'GOOGLE_MAX_PER_MINUTE'));

		// Reset/initialize daily
		if( !isset( $limits['GOOGLE_MAX_PER_DAY'] ) or $limits['GOOGLE_MAX_PER_DAY']['date'] != date('Y-m-d'))
		{
			$limits['GOOGLE_MAX_PER_DAY']['date'] = date('Y-m-d');

			$limits['GOOGLE_MAX_PER_DAY']['count'] = 0;
		}
		
		// Reset/initialize hourly
		if( !isset( $limits['GOOGLE_MAX_PER_HOUR'] ) or $limits['GOOGLE_MAX_PER_HOUR']['date'] != date('Y-m-d H'))
		{
			$limits['GOOGLE_MAX_PER_HOUR']['date'] = date('Y-m-d H');

			$limits['GOOGLE_MAX_PER_HOUR']['count'] = 0;
		}

		// Reset/initialize minute
		if( !isset( $limits['GOOGLE_MAX_PER_MINUTE'] ) or $limits['GOOGLE_MAX_PER_MINUTE']['date'] != date('Y-m-d H:i'))
		{
			$limits['GOOGLE_MAX_PER_MINUTE']['date'] = date('Y-m-d H:i');

			$limits['GOOGLE_MAX_PER_MINUTE']['count'] = 0;
		}

		// Check daily
		if($limits['GOOGLE_MAX_PER_DAY']['count'] >= self::MAX_PER_DAY)
		{
			return array( 'status' => false, 'reason' => sprintf( 'Daily Google hit limit exceeded (%d)', self::MAX_PER_DAY ) );
		}

		// Check hourly
		if($limits['GOOGLE_MAX_PER_HOUR']['count'] >= self::MAX_PER_HOUR)
		{
			return array( 'status' => false, 'reason' => sprintf( 'Hourly Google hit limit exceeded (%d)', self::MAX_PER_HOUR ) );
		}

		// Check minute
		if($limits['GOOGLE_MAX_PER_MINUTE']['count'] >= self::MAX_PER_MINUTE)
		{
			return array( 'status' => false, 'reason' => sprintf( 'Per-minute Google hit limit exceeded (%d)', self::MAX_PER_MINUTE ) );
		}

		// All good, incriment the counts...
		$limits['GOOGLE_MAX_PER_DAY']['count']++;
		$limits['GOOGLE_MAX_PER_HOUR']['count']++;
		$limits['GOOGLE_MAX_PER_MINUTE']['count']++;
		
		$this->getMemcacheDbConnection()->set('GOOGLE_MAX_PER_DAY', $limits['GOOGLE_MAX_PER_DAY']);
		$this->getMemcacheDbConnection()->set('GOOGLE_MAX_PER_HOUR', $limits['GOOGLE_MAX_PER_HOUR']);
		$this->getMemcacheDbConnection()->set('GOOGLE_MAX_PER_MINUTE', $limits['GOOGLE_MAX_PER_MINUTE']);

		return array( 'status' => true );
	}

	/**
	 * Parses $data into $this depending what $data is
	 *
	 * @param array $data
	 */
	private function populate($data)
	{
		if(array_key_exists('data_source', $data) and array_key_exists('results', $data))
		{
			$this->hydrate($data);

			$data = $data['results'];
		}

		foreach($data as $record)
		{
			$this->hydrate($record);

			if(is_numeric($record['latitude']) and is_numeric($record['longitude']))
			{
				$this->addResponse($record);
			}

		}
	}

	/**
	 * Assigns vars in $data directly to $this
	 *
	 * @param array $data
	 */
	private function hydrate($data)
	{
		$valid = array
		(
			'GeocoderResultId' => 'setGeocoderResultId',
			'geocoder_result_id' => 'setGeocoderResultId',
			'CreatedAt' => 'setCreatedAt',
			'created_at' => 'setCreatedAt',
			'UpdatedAt' => 'setUpdatedAt',
			'updated_at' => 'setUpdatedAt'
		);

		foreach($data as $key=>$value)
		{
			if(array_key_exists($key, $valid))
			{
				$method = $valid[$key];

				$this->$method($value);
			}
		}
	}

	/**
	 * Places vars on hold, attempts to hit google
	 * and places the vars back if google fails.
	 *
	 */
	private function refreshExpired()
	{
		$this->skipCache();

		$responseCode = $this->getResponseCode();

		$responseMessage = $this->getResponseMessage();

		$responses = $this->getResponse();

		$this->responseCode = '';

		$this->responseMessage = '';

		$this->responses = array();

		$status = $this->doExecute();

		// Put them back on google failure
		if($this->getResponseCode() == 'failure')
		{
			$this->responses = $responses;

			$this->responseCode = $responseCode;

			$this->responseMessage = $responseMessage;
		}
	}

	/**
	 * General error handeling method for reporting problems.
	 *
	 * @param string $e
	 */
	private static function logError($e)
	{
		error_log("Geocoder: $e", 1, 'robert.powell@aresdirect.com,mark.hughes@aresdirect.com', "subject:Geocoder Error\n");
#		$fp = fopen( '/tmp/geocoder_errors.log', 'a' );
#		fwrite( $fp, print_r( $e, true ) );
#		fclose( $fp );
	}
	
	///////////////////
	// CACHE METHODS //
	///////////////////

	/**
	 * Enforces all enabled cache policies
	 *
	 * @return void
	 */
	private function generateCache()
	{
		if($this->getConnection())
		{
			if(!$this->generateDatabaseCache())
			{
				return;
			}
		}

		$this->generateQuickCache();
	}

	/**
	 * Method to call memcaches and skip db cache
	 *
	 */
	private function generateQuickCache()
	{
		if($this->getMemcacheDbConnection())
		{
			$this->generateMemcacheDbCache();
		}
	}

	/**
	 * Writes the responses to all database tables.
	 */
	private function generateDatabaseCache()
	{
		try
		{
			$this->getConnection()->beginTransaction();

			$now = @date('Y-m-d H:i:s', time());

			// STEP ONE: Create the base record
			$sql = "insert into geocoder_results (`id`, `status`, `address_hash`, `input_address`, `created_at`, `updated_at`) values (null, ?, ?, ?, ?, ?) on duplicate key update updated_at = ?, status = ?, id = LAST_INSERT_ID(id)";
			
			$bindings = array
			(
				$this->getStatus(),
				$this->getAddressHash(),
				$this->getInputAddress(),
				$now,
				$now,
				$now,
				$this->getStatus()
			);

			$this->getConnection()->prepare($sql)->execute($bindings);

			$this->setGeocoderResultId($this->getConnection()->lastInsertId());

			// STEP 2: Delete existing locations
			$sql = "delete from geocoder_result_addresses where geocoder_result_id = ?";

			$bindings = array($this->getGeocoderResultId());

			$this->getConnection()->prepare($sql)->execute($bindings);

			// STEP 3: Create the related data
			for($i=0; $i<count($this->responses); $i++)
			{
				$sql = "insert into geocoder_result_addresses (`id`, `geocoder_result_id`, `address`, `city`, `county`, `state`, `country`, `zip`, `latitude`, `longitude`, `score`) values (null, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

				$bindings = array
				(
					$this->getGeocoderResultId(),
					@$this->responses[$i]['address'],
					@$this->responses[$i]['city'],
					@$this->responses[$i]['county'],
					@$this->responses[$i]['state'],
					@$this->responses[$i]['country'],
					@$this->responses[$i]['zip'],
					@$this->responses[$i]['latitude'],
					@$this->responses[$i]['longitude'],
					@$this->responses[$i]['score'],
				);

				$this->getConnection()->prepare($sql)->execute($bindings);
			}

			if(!$this->getCreatedAt())
			{
				$this->setCreatedAt($now);
			}

			$this->setUpdatedAt($now);

			$this->getConnection()->commit();
		}
		catch(Exception $e)
		{
			try
			{
				$this->getConnection()->rollBack();
			}
			catch(Exception $e)
			{
				$this->logError("Failed to rollback: $e");
			}

			$this->logError("Failed to write to mysql: $e");

			return false;
		}

		return true;
	}

	/**
	 * Sticks the results into memcacheDB
	 *
	 */
	private function generateMemcacheDbCache()
	{
		try
		{
			if($this->getMemcacheDbConnection())
			{
				$this->getMemcacheDbConnection()->set($this->getAddressHash(), $this->getResponseSerialized());
			}
		}
		catch(Exception $e)
		{
			$this->logError($e);
		}
	}

	///////////////////////////////////////
	// METHODS TO REPRESENT THE RESPONSE //
	///////////////////////////////////////

	/**
	 * Gets an array of everything needed to
	 * rebuild this object
	 *
	 * @return array
	 */
	public function getResponse()
	{
		// This needs to be updated in getResponseXml() too.
		$response = array
		(
			'geocoder_result_id' => $this->getGeocoderResultId(),
			'status'             => $this->getStatus(),
			'input_address'      => $this->getInputAddress(),
			'address_hash'       => $this->getAddressHash(),
			'created_at'         => $this->getCreatedAt(),
			'updated_at'         => $this->getUpdatedAt(),
			'source'             => $this->getSource(),
			'data_source'        => $this->getDataSource(),
			'response_code'      => $this->getResponseCode(),
			'response_message'   => $this->getResponseMessage(),
			'results'            => $this->getResponses()
		);

		return $response;
	}

	/**
	 * Returns the response in json fromat
	 */
	public function getResponseJson()
	{
		return json_encode($this->getResponse());
	}

	/**
	 * Returns the response in php serialize format
	 */
	public function getResponseSerialized()
	{
		return serialize($this->getResponse());
	}

	//////////////////////////
	// CONNECTION ACCESSORS //
	//////////////////////////

	/**
	 * Gets the connection. Return false to ignore db lookups and write.
	 * Lookup is done inline for performace. May need to write a more
	 * elaborate solution which parses databases.yml.
	 *
	 * @return PDO
	 */
	public static function getConnection()
	{
		try
		{
			static $connection;

			if($connection instanceof PDO and !self::testConnectionParam())
			{
				return $connection;
			}
			
			switch( ZoneResolver::Resolve() )
			{
				case 'stage':
				case 'deploy':
				
					$hostname	=	'dbmain.colo.aresdirect.com';
					$password	=	'69w8EbapHe4A5ra#ujuf+e6e';
					break;
				
				default:
				
					$hostname	=	'devdb.main.aresdirect.com';
					$password	=	'*hEs9?gaf7daduq_br2+4t7-';
					break;
			}
			
#insert into user ( Host, User, Password ) values ( '10.100.2.0/255.255.255.0', 'geocoder_rw', PASSWORD( '69w8EbapHe4A5ra#ujuf+e6e' ) );
#insert into db ( Host, User, Db, Select_priv, Insert_priv, Update_priv, Delete_priv ) values ( '10.100.2.0/255.255.255.0', 'geocoder_rw', 'geocoder', 'Y', 'Y', 'Y', 'Y' );

#insert into user ( Host, User, Password ) values ( '10.50.0.0/255.255.0.0', 'geocoder_rw', PASSWORD( '*hEs9?gaf7daduq_br2+4t7-' ) );
#insert into db ( Host, User, Db, Select_priv, Insert_priv, Update_priv, Delete_priv ) values ( '10.50.0.0/255.255.0.0', 'geocoder_rw', 'geocoder', 'Y', 'Y', 'Y', 'Y' );
			
			$connection = new PDO( 'mysql:dbname=geocoder;host='.$hostname, self::testConnectionParam('mysql'), $password );
			
			$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

			return $connection;
		}
		catch(Exception $e)
		{
			self::logError($e);
		}

		return false;
	}

	/**
	 * Set a connection here to enable memcacheDB
	 * read/writes/
	 *
	 * @return Memcache
	 */
	public static function getMemcacheDbConnection()
	{
		try
		{
			static $con;

			if(!is_object($con) or self::testConnectionParam())
			{
				$con	=	new Memcache();
				
				switch( ZoneResolver::Resolve() )
				{
					case 'stage':
					case 'deploy':
					
						$hostname	=	'dbslave1.colo.aresdirect.com';
						break;
					
					default:
					
						### TODO: fix this - devdb perhaps?
						$hostname	=	'10.50.7.50';
				}
				
				if( !$con->pconnect( $hostname, self::testConnectionParam('memcacheDB' ) ) )
					throw new Exception("Failed to connect to memcacheDB.");
			}
			
			return $con;
		}
		catch(Exception $e)
		{
			self::logError($e);
		}

		return false;
	}

	/**
	 * A hack into the code to test connection params
	 *
	 */
	public static function testConnectionParam($get = '', $set = '')
	{
		static $isTest;

		if($get == 'memcacheDB')
		{
			if($isTest)
			{
				return 123456;
			}

			return 21201;
		}
		elseif($get == 'mysql')
		{
			if($isTest)
			{
				return 'rdrr';
			}

			return 'appserver';
		}

		if($set == 'set')
		{
			$isTest = true;
		}
		elseif($set == 'unset')
		{
			$isTest = false;
		}

		return $isTest;
	}

	////////////////////
	// HELPER METHODS //
	////////////////////

	/**
	 * Checks if the updated_at time is less than now
	 *
	 * @return boolean
	 */
	public function isExpired()
	{
		$case1 = (@strtotime($this->getUpdatedAt()) + Geocoder::EXPIRE_TIME) < time();

		$case2 = @strtotime($this->getUpdatedAt()) < Geocoder::OLDEST_VALID;

		if($case1 or $case2)
		{
			return true;
		}

		return false;
	}

	/**
	 * Tells the execute method to skip cache and call external
	 * resulting in an update of the database and caches
	 *
	 */
	public function skipCache()
	{
		$this->skipCache = true;
	}

	/**
	 * Tells the execute method to use mysql even when skipCache
	 * is passed
	 *
	 */
	public function useMysql()
	{
		$this->useMysql = true;
	}

	///////////////////////////
	/// GETTERS AND SETTERS ///
	///////////////////////////

	/**
	 * Add a geocoder response to the stack
	 *
	 * @param array $response
	 * @param string $geocoder
	 */
	private function addResponse($response)
	{
		if(!is_array($this->responses))
		{
			$this->responses = array();
		}

		unset($response['geocoderResultId']);
		unset($response['status']);
		unset($response['addressHash']);
		unset($response['createdAt']);
		unset($response['updatedAt']);
		unset($response['geocoderResultAddressId']);

		$this->responses[] = $response;
	}

	/**
	 * Get all responses added by geocoders
	 *
	 * @return array or empty
	 */
	public function getResponses()
	{
		if(!is_array($this->responses))
		{
			$this->responses = array();
		}

		return $this->responses;
	}

	/**
	 * Generates an md5() hash of the input address
	 *
	 * @return string
	 */
	public function getAddressHash()
	{
		// Strip all non alphanumeric characters from input
		$inputAddress	=	trim( strtolower(preg_replace("/[^a-zA-Z0-9]/", "", $this->getInputAddress())) );

		return md5($inputAddress);
	}

	/**
	 * Gets key to call google with
	 *
	 * @return string
	 */
	public function getGoogleKey()
	{
		return 'ABQIAAAAkF20VQl7lW00JVlnFnONGhSlqiNEij4gob3OPtHPa5j0M3fbZRT8RyYebv0ZmEfN7UuMmlloy5HdwQ'; // free - communitysoftwarelab.com
	}

	/**
	 * Getter for geocoderResultId
	 *
	 * @return int
	 */
	public function getGeocoderResultId()
	{
		return $this->geocoderResultId;
	}

	/**
	 * Private setter for geocoder_result_id
	 *
	 * @param int $geocoderResultId
	 */
	private function setGeocoderResultId($geocoderResultId)
	{
		$this->geocoderResultId = $geocoderResultId;
	}

	/**
	 * Gets the input address passed to the constructor
	 *
	 * @return string
	 */
	public function getInputAddress()
	{
		return $this->inputAddress;
	}

	/**
	 * Sets the input addres from an array or string
	 *
	 * @param mixed $inputAddress
	 * @return void
	 */
	public function setInputAddress($inputAddress)
	{
		if(is_array($inputAddress))
		{
			$inputAddress = $this->createFullAddress($inputAddress);
		}

		$this->inputAddress = $inputAddress;
	}

	/**
	 * Gets the created_at time of the response
	 *
	 * @return int
	 */
	public function getCreatedAt()
	{
		return $this->createdAt;
	}

	/**
	 * Sets the creation date
	 *
	 * @param mixed int/date $date
	 * @return void
	 */
	private function setCreatedAt($date)
	{
		if(is_numeric($date))
		{
			$date = @date('Y-m-d H:m:i', $date);
		}

		$this->createdAt = $date;
	}

	/**
	 * Gets the updated_at time of the response
	 *
	 * @return int
	 */
	public function getUpdatedAt()
	{
		return $this->updatedAt;
	}

	/**
	 * Sets the update date
	 *
	 * @param mixed int/date $date
	 * @return void
	 */
	private function setUpdatedAt($date)
	{
		if(is_numeric($date))
		{
			$date = @date('Y-m-d H:m:i', $date);
		}

		$this->updatedAt = $date;
	}

	/**
	 * Gets the status of the response
	 *
	 * @return string
	 */
	public function getStatus()
	{
		if(count($this->getResponses()) == 1)
		{
			return 'match';
		}
		elseif(count($this->getResponses()) > 1)
		{
			return 'suggestions';
		}
		else
		{
			return 'miss';
		}
	}

	/**
	  *	Accessor for where the response came from in most general terms
	  *	@return string
	  */
	
	public function getSource()
	{
		switch( $this->getDataSource() )
		{
			case 'mysql':
			case 'memcacheDB':	return 'internal';
			case 'throttle':    return 'internal_fail';
			case 'external':	if( $this->responseCode == 'failure' )
									return 'external_fail';
								else
									return 'external';
		}
	}

	/**
	 * Getter for where the data came from
	 *
	 * @return string
	 */
	public function getDataSource()
	{
		return $this->dataSource;
	}

	/**
	 * If the encoding was a success or failure as a simple
	 * string of 'success' or 'failure'
	 *
	 * @return string
	 */
	public function getResponseCode()
	{
		return $this->responseCode;
	}

	/**
	 * The reason for a responseCode of 'failure'
	 *
	 * @return unknown
	 */
	public function getResponseMessage()
	{
		return $this->responseMessage;
	}

	/**
	 * Parses $response array data into a string response
	 *
	 * @param array $response
	 * @return string
	 */
	public static function createFullAddress($response)
	{
		if(!is_array($response))
		{
			return $response;
		}

		$response = array_change_key_case($response, CASE_LOWER);

		$response = array
		(
			@$response['address'],
			@$response['city'],
			@$response['state'],
			@$response['zip'],
			@$response['county'],
			@$response['country'],
		);

		$fullAddress = implode(' ', $response);

		while(preg_match('/  /', $fullAddress))
		{
			$fullAddress = str_replace('  ', ' ', $fullAddress);
		}

		return trim($fullAddress);
	}
	
	public function readEntriesFromCache( $locations )
	{
		$memcached										=	self::getMemcacheDbConnection();
		
		if( !$memcached instanceof memcache )
			return array();
		
		$returnArray						=	array();
		
		$origDS								=	$this->dataSource;
		$origAdd							=	$this->inputAddress;
		
		$hashes								=	array();
		$revHash							=	array();
		
		foreach( $locations as $key => $value )
		{
			$this->setInputAddress( $value );
			$hashes[$key]								=	$this->getAddressHash();
			$revHash[$hashes[$key]][]					=	$key;
		}
		
		$search											=	$memcached->get( $hashes );
		
		$this->dataSource						=	'memcacheDB';
		
		foreach( $search as $key => $value )
		{
			if( $key == '320722549d1751cf3f247855f937b982' )
				continue;
			
			$returnedData								=	unserialize( $value );
			
			$this->setInputAddress( $returnedData['input_address'] );
			$this->populate( $returnedData );
			
			$response								=	$this->getResponse(); #json_decode( json_encode( $this->getResponse() ), true );
			
			foreach( $revHash[$key] as $originalKey )
			{
				$response['source']					=	'internal';
				$response['data_source']			=	'memcacheDB';
				
				$returnArray[$originalKey]			=	$response;
			}
		}
		
		$this->dataSource							=	$origDS;
		$this->setInputAddress( $origAdd );
		
		return $returnArray;
	}
}
