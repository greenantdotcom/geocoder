<?php

require_once('geocoder.php');

class GeocoderModel
{
	/**
	 * Gets all locations where status = 'miss'.
	 *
	 * @return array or false
	 */
	public static function getUnresolvedLocations($offset = 0, $limit = 20)
	{
		if(!is_numeric($offset))
		{
			$offset = 0;
		}

		if(!is_numeric($limit) or $limit > 20)
		{
			$limit = 20;
		}

		$sql = "select * from geocoder_results where status = 'miss' limit $offset, $limit";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute();

		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $results;
	}

	/**
	 * Gets a count of all locations where status='miss'
	 *
	 * @return int
	 */
	public static function countUnresolvedLocations()
	{
		$sql = "select count(*) as unresolved from geocoder_results where status = 'miss'";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute();

		$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $result[0]['unresolved'];
	}

	/**
	 * Gets all locations where status = 'suggestions' with offset,limit
	 *
	 * @param int $offset
	 * @param int $limit
	 * @return array or false
	 */
	public static function getLocationsWithSuggestions($offset = 0, $limit = 20)
	{
		if(!is_numeric($offset))
		{
			$offset = 0;
		}

		if(!is_numeric($limit) or $limit > 20)
		{
			$limit = 20;
		}

		$sql = "select * from geocoder_results where status = 'suggestions' limit $offset, $limit";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute();

		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $results;
	}

	/**
	 * Get a count of all locations where status='suggestions'
	 *
	 * @return int
	 */
	public static function countLocationsWithSuggestions()
	{
		$sql = "select count(*) as suggestions from geocoder_results where status = 'suggestions'";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute();

		$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $result[0]['suggestions'];
	}

	/**
	 * Gets all locations where status = 'match' with offset,limit
	 *
	 * @param int $offset
	 * @param int $limit
	 * @return array or false
	 */
	public static function getResolvedLocations($offset = 0, $limit = 20)
	{
		if(!is_numeric($offset))
		{
			$offset = 0;
		}

		if(!is_numeric($limit) or $limit > 20)
		{
			$limit = 20;
		}

		$sql = "select * from geocoder_results where status = 'match' limit $offset, $limit";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute();

		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $results;
	}

	/**
	 * Get a count of all locations where status='match'
	 *
	 * @return int
	 */
	public static function countResolvedLocations()
	{
		$sql = "select count(*) as matched from geocoder_results where status = 'match'";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute();

		$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $result[0]['matched'];
	}

	/**
	 * Looks at hotelMetaData and fetches the location by hotelId.
	 *
	 * @param int $hotelId
	 * @return array
	 */
	public static function getLocationsByHotelId($hotelId)
	{
		$sql = "select hmd.hotelAddress as address, hmd.hotelCity as city, hmd.hotelState as state, hmd.hotelPostalCode as zip, hmd.hotelCountry as country, hmd.hotelID as hotelId, htcs.channelHotelID as channelHotelId from hotelMetaData hmd left join hotelToChannelSource htcs on hmd.hotelID = htcs.hotelID where hmd.hotelID = ?";

		$bindings = array($hotelId);

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute($bindings);

		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$responses = array();

		foreach($results as $result)
		{
			$geocoder = new Geocoder($result);

			$geocoder->execute();

			$response = $geocoder->getResponse();

			$response['hotelId'] = $result['hotelId'];

			$response['channelHotelId'] = $result['channelHotelId'];

			$responses[] = $response;
		}

		return $responses;
	}

	/**
	 * Looks at hotelMetaData and fetches the location by channelHotelId
	 *
	 * @param string $channelHotelId
	 * @return array
	 */
	public static function getLocationsByHotelChannelId($channelHotelId)
	{
		$sql = "select hmd.hotelAddress as address, hmd.hotelCity as city, hmd.hotelState as state, hmd.hotelPostalCode as zip, hmd.hotelCountry as country, hmd.hotelID as hotelId, htcs.channelHotelID as channelHotelId from hotelMetaData hmd join hotelToChannelSource htcs on hmd.hotelID = htcs.hotelID where htcs.channelHotelId = ?";

		$bindings = array($channelHotelId);

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute($bindings);

		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		$responses = array();

		foreach($results as $result)
		{
			$geocoder = new Geocoder($result);

			$geocoder->execute();

			$response = $geocoder->getResponse();

			$response['hotelId'] = $result['hotelId'];

			$response['channelHotelId'] = $result['channelHotelId'];

			$responses[] = $response;
		}

		return $responses;
	}

	/**
	 * Fetch a record from the geocoder_results table by id
	 *
	 * @param int $id
	 * @return array or false
	 */
	public static function retreiveById($id)
	{
		$sql = "select
					gr.id as geocoderResultId,
					gr.status,
					gr.input_address as inputAddress,
					gr.address_hash as addressHash,
					gr.created_at as createdAt,
					gr.updated_at as updatedAt,
					gra.id as geocoderResultAddressId,
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
								where gr.id = ?";
		$bindings = array($id);

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute($bindings);

		$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return $results;
	}

	/**
	 * Fetch a record by the geocoder_result_addresses by id
	 *
	 * @param int $id
	 */
	public static function retrieveAddressById($id)
	{
		$sql = "select * from geocoder_result_addresses where id = ?";

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute(array($id));

		$address = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt->closeCursor();

		unset($stmt);

		return $address;
	}

	/**
	 * Saves a known geocoder_result_addresses or creates
	 * a new record if $location has no ['id']
	 *
	 * @param array $location
	 */
	public static function saveAddress($location)
	{
		if(!array_key_exists('geocoder_result_id', $location) or !is_numeric($location['geocoder_result_id']))
		{
			throw new Exception("geocoder_result_id is required and must be numeric. You passed: " . @$location['geocoder_result_id']);

			return;
		}

		// If we are updating an existing record
		if(is_numeric(@$location['id']))
		{
			$sql = "update geocoder_result_addresses set address = ?, city = ?, county = ?, state = ?, country = ?, zip = ?, latitude = ?, longitude = ?, score = ? where id = ?";

			$bindings = array
			(
				$location['address'],
				$location['city'],
				$location['county'],
				$location['state'],
				$location['country'],
				$location['zip'],
				$location['latitude'],
				$location['longitude'],
				$location['score'],
				$location['id']
			);

			Geocoder::getConnection()->prepare($sql)->execute($bindings);
		}
		else
		{
			$sql = "insert into geocoder_result_addresses (`id`, `geocoder_result_id`, `address`, `city`, `county`, `state`, `country`, `zip`, `latitude`, `longitude`, `score`) values (null, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

			$bindings = array
			(
				$location['geocoder_result_id'],
				$location['address'],
				$location['city'],
				$location['county'],
				$location['state'],
				$location['country'],
				$location['zip'],
				$location['latitude'],
				$location['longitude'],
				$location['score']
			);

			Geocoder::getConnection()->prepare($sql)->execute($bindings);
		}

		self::updateResultsStatus($location['geocoder_result_id']);
	}

	/**
	 * Calculates the status based on data and updates accordingly
	 *
	 * @param int $id
	 */
	public static function updateResultsStatus($id)
	{
		// Get the count to determine the status
		$sql = "select count(*) as addresses from geocoder_result_addresses where geocoder_result_id = ?";

		$bindings = array($id);

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute($bindings);

		$results = $stmt->fetch(PDO::FETCH_ASSOC);

		$stmt->closeCursor();

		unset($stmt);

		$results = $results['addresses'];

		if($results == 1)
		{
			$status = 'match';
		}
		elseif($results > 1)
		{
			$status = 'suggestions';
		}
		else
		{
			$status = 'miss';
		}

		// Update the base record
		$sql = "update geocoder_results set status = ?, updated_at = current_timestamp where id = ?";

		$bindings = array($status, $id);

		Geocoder::getConnection()->prepare($sql)->execute($bindings);
	}

	/**
	 * Delete an address from the related geocoder_result_addresses
	 * by $id
	 *
	 * @param int $id
	 * @return boolean
	 */
	public static function deleteAddress($id)
	{
		$address = self::retrieveAddressById($id);

		if(!is_array($address))
		{
			return false;
		}

		$sql = "delete from geocoder_result_addresses where id = ?";

		$bindings = array($id);

		$stmt = Geocoder::getConnection()->prepare($sql);

		$stmt->execute($bindings);

		$stmt->closeCursor();

		unset($stmt);

		self::updateResultsStatus($address['geocoder_result_id']);

		return true;
	}
}