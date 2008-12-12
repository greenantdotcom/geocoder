<?php

//phpunit --coverage-html /httpd/apps/geocoder_webservice/docroot/tests/ GeocoderTest.php

date_default_timezone_set('America/Los_Angeles');

require_once(realpath(dirname(__FILE__)) . '/../lib/geocoder.php');

/**
 * Test of the geocoder
 *
 */
class GeocoderTest extends PHPUnit_Framework_TestCase
{
	/**
	 * Ensure memcacheDB is up and serving data. Hits once to
	 * ensure cache and again to pull from cache.
	 */
	public function testeMcacheDBResponse()
	{
		$address = '3750 Convoy Street, San Diego, CA 92111';

		$data = $this->getData($address);

		$data = $this->getData($address);

		$this->assertEquals($data[0]->data_source, 'memcacheDB', "Failed to validate that memcacheDB works.");
	}

	/**
	 * The purpose of this test is to ensure that if
	 * the hashing algorithm changes, its impact is know.
	 *
	 */
	public function testAddressHash()
	{
		$address = '3750 Convoy Street, San Diego, CA 92111';

		$hash = md5(strtolower(preg_replace("/[^a-zA-Z0-9]/", "", $address)));

		$data = $this->getData($address);

		$this->assertEquals($data[0]->address_hash, $hash, "Looks like the address hashing changed.");
	}

	/**
	 * Ensures that we can hit google by skipping the cache.
	 *
	 */
	public function testGoogle()
	{
		$address = '3750 Convoy Street, San Diego, CA 92111';

		$data = $this->getData($address, true);

		$this->assertEquals($data[0]->data_source, 'external', "Failed to get data from external/google. Maybe the skipCache logic is broken.");
	}

	/**
	 * Validates data structure and that data is
	 * populated for a known match.
	 *
	 */
	public function testMatch()
	{
		$addresses = array
		(
			'1600 Amphitheatre Pkwy, Mountain View, CA 94043',
			'570 Pond Promenade Craver Chanhassen MN 55317 US',
			'66 Northampton Road Market Harborough Leicester LE16 9HE GB'
		);

		foreach($addresses as $address)
		{
			$data = $this->getData($address);

			$this->checkMatch($data);
		}
	}

	/**
	 * Test the data structure and response for a suggestion
	 * with the input address having both a North East and
	 * South East match.
	 *
	 */
	public function testSuggestion()
	{
		$addresses = array
		(
			'420 high st, salem oregon',
			'OX14 4PG'
		);

		foreach($addresses as $address)
		{
			$data = $this->getData($address);

			$this->assertGreaterThan(1, count($data[0]->results), "Expected suggestions");

			$this->assertEquals($data[0]->status, 'suggestions', 'Expected status to be suggestions');
		}
	}

	/**
	 * Test that a bad address gives a status of 'miss' with zero results
	 *
	 */
	public function testMiss()
	{
		$addresses = array
		(
			'asdf'
		);

		foreach($addresses as $address)
		{
			$data = $this->getData($address);

			$this->assertEquals(count($data[0]->results), 0, "Expected no results");

			$this->assertEquals($data[0]->status, 'miss', 'Expected status to be miss');
		}
	}

	/**
	 * Test to get some more code coverage and ensure that
	 * the json response is always the same as the array response.
	 *
	 */
	public function testGeocoderResponses()
	{
		$geocoder = new Geocoder('1600 Amphitheatre Pkwy, Mountain View, CA 94043');

		$geocoder->execute();

		$data = json_decode($geocoder->getResponseJson());

		$this->checkMatch(array($data));

		$this->assertEquals($geocoder->getResponseJson(), json_encode($geocoder->getResponse()));
	}

	/**
	 * A test which passes skipCache to hit google and subsuquently
	 * test all cache writes.
	 *
	 */
	public function testGeocoderCache()
	{
		$addresses = array
		(
			'1600 Amphitheatre Pkwy, Mountain View, CA 94043',
			'san diego, ca',
			'570 Pond Promenade Craver Chanhassen MN 55317 US',
			'harvard university'

		);

		foreach($addresses as $address)
		{
			$geocoder = new Geocoder($address);

			$geocoder->skipCache();

			$geocoder->execute();

			$data = json_decode($geocoder->getResponseJson());

			$this->assertEquals($data->data_source, 'external', "Failed to get data from external/google. Maybe the skipCache logic is broken.");

			$this->assertEquals($data->response_code , 'success', "Expected a successfull lookup.");
		}


	}

	/**
	 * Ensure mysql returns a properly structured response
	 *
	 */
	public function testMysql()
	{
		$geocoder = new Geocoder('1600 Amphitheatre Pkwy, Mountain View, CA 94043');

		$geocoder->useMysql();

		$geocoder->execute();

		$data = json_decode($geocoder->getResponseJson());

		$this->checkMatch(array($data));
	}

	/**
	 * Double check that the address creation method is the same
	 * and that the user changing it knows the impact of modifying it.
	 *
	 */
	public function testAddressCreation()
	{
		$address = array
		(
			'address'   => '1600 Amphitheatre Pkwy',
			'city'      => 'Mountain View',
			'state'     => 'CA',
			'county'    => '',
			'country'   => 'US',
			'zip'       => '94043',
			'longitude' => '-122.085121',
			'latitude'  => '37.423088',
			'score'     => '100'
		);

		$geocoder = new Geocoder($address);

		$created = $geocoder->getInputAddress();

		$response = array
		(
			$address['address'],
			$address['city'],
			$address['state'],
			$address['zip'],
			$address['county'],
			$address['country'],
		);

		$fullAddress = implode(' ', $response);

		while(preg_match('/  /', $fullAddress))
		{
			$fullAddress = str_replace('  ', ' ', $fullAddress);
		}

		$fullAddress = trim($fullAddress);

		$this->assertEquals($fullAddress, $created, "Address creation method changed.");
	}

	/**
	 * Helper method to pass addresses which should be valid matches
	 *
	 * @param array $data
	 */
	public function checkMatch($data)
	{
		$this->assertEquals(count($data[0]->results), 1, "Expected one result but got " . count($data[0]->results));

		$this->assertEquals($data[0]->status, 'match', "Unexpected value for status. Expected 'match'");

		$this->assertEquals($data[0]->response_code, 'success', "Expected response code 'success'");

		$location = $data[0]->results[0];

		$this->assertThat($location->address, $this->logicalNot($this->equalTo('')), "Unexpected value for address");

		$this->assertThat($location->city, $this->logicalNot($this->equalTo('')), "Unexpected value for city");

		$this->assertThat($location->state, $this->logicalNot($this->equalTo('')), "Unexpected value for state");

		$this->assertThat($location->zip, $this->logicalNot($this->equalTo('')), "Unexpected value for zip");

		$this->assertThat($location->country, $this->logicalNot($this->equalTo('')), "Unexpected value for country");

		$this->assertThat($location->latitude, $this->logicalNot($this->equalTo('')), "Unexpected value for latitude");

		$this->assertThat($location->longitude, $this->logicalNot($this->equalTo('')), "Unexpected value for longitude");

		$this->assertGreaterThan('89', $location->score, "Score should be a match > 89");
	}

	/**
	 * Test that the daily throttle is respected
	 *
	 */
	public function testExceedDailyLimit()
	{
		// Put them on hold
		$limit = Geocoder::getMemcacheDbConnection()->get('GOOGLE_MAX_PER_DAY');

		// Test the daily limit
		$tempLimit = $limit;
		$tempLimit['date'] = date('Y-m-d');
		$tempLimit['count'] = Geocoder::MAX_PER_DAY;
		Geocoder::getMemcacheDbConnection()->set('GOOGLE_MAX_PER_DAY', $tempLimit);

		// Run it
		$geocoder = new Geocoder('1600 Amphitheatre Pkwy, Mountain View, CA 94043');
		$geocoder->skipCache();
		$geocoder->execute();
		$data = $geocoder->getResponse();

		// Put it back
		Geocoder::getMemcacheDbConnection()->set('GOOGLE_MAX_PER_DAY', $limit);

		// test it
		$this->assertEquals('failure', $data['response_code'], "Daily limit not being respected");
		$this->assertEquals('Exceeded google hit limit', $data['response_message'], "Response message for google failure changed");
	}

	/**
	 * Test that the hourly throttle is respected
	 *
	 */
	public function testExceedHourlyLimit()
	{
		// Put them on hold
		$limit = Geocoder::getMemcacheDbConnection()->get('GOOGLE_MAX_PER_HOUR');

		// Test the daily limit
		$tempLimit = $limit;
		$tempLimit['date'] = date('Y-m-d H');
		$tempLimit['count'] = Geocoder::MAX_PER_HOUR;
		Geocoder::getMemcacheDbConnection()->set('GOOGLE_MAX_PER_HOUR', $tempLimit);

		// Run it
		$geocoder = new Geocoder('1600 Amphitheatre Pkwy, Mountain View, CA 94043');
		$geocoder->skipCache();
		$geocoder->execute();
		$data = $geocoder->getResponse();

		// Put it back
		Geocoder::getMemcacheDbConnection()->set('GOOGLE_MAX_PER_HOUR', $limit);

		// test it
		$this->assertEquals('failure', $data['response_code'], "Hourly limit not being respected");
		$this->assertEquals('Exceeded google hit limit', $data['response_message'], "Response message for google failure changed");
	}

	/**
	 * Test that the minute throttle is respected
	 *
	 */
	public function testExceedMinuteLimit()
	{
		// Put them on hold
		$limit = Geocoder::getMemcacheDbConnection()->get('GOOGLE_MAX_PER_MINUTE');

		// Test the daily limit
		$tempLimit = $limit;
		$tempLimit['date'] = date('Y-m-d H:i');
		$tempLimit['count'] = Geocoder::MAX_PER_MINUTE;
		Geocoder::getMemcacheDbConnection()->set('GOOGLE_MAX_PER_MINUTE', $tempLimit);

		// Run it
		$geocoder = new Geocoder('1600 Amphitheatre Pkwy, Mountain View, CA 94043');
		$geocoder->skipCache();
		$geocoder->execute();
		$data = $geocoder->getResponse();

		// Put it back
		Geocoder::getMemcacheDbConnection()->set('GOOGLE_MAX_PER_MINUTE', $limit);

		// test it
		$this->assertEquals('failure', $data['response_code'], "Minute limit not being respected");
		$this->assertEquals('Exceeded google hit limit', $data['response_message'], "Response message for google failure changed");
	}

	/**
	 * Uses a hook in connection params to force an error
	 *
	 */
	public function testBadConnections()
	{
		$geocoder = new Geocoder('1600 Amphitheatre Pkwy, Mountain View, CA 94043');

		Geocoder::testConnectionParam(false, 'set');

		$geocoder->execute();

		Geocoder::testConnectionParam(false, 'unset');

		$data = $geocoder->getResponse();

		$this->assertEquals($data['data_source'], 'external', "Did not fetch data from google although connections should fail.");
	}

	/**
	 * Simple method to pull address data from the resolver.
	 *
	 * @param string $address
	 * @param boolean $skipCache
	 * @return array
	 */
	public function getData($address, $skipCache = false)
	{
			$url = 'http://10.50.7.50:8888/resolver.php?location[address]=' . urlencode($address);

			if($skipCache)
			{
				$url .= '&skipCache=true';
			}

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, $url);
			curl_setopt($ch, CURLOPT_HEADER, 0);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			$data = curl_exec($ch);
			curl_close($ch);

			$data = json_decode($data);

			return $data;
	}
}