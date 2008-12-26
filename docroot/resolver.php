<?php
	
	header( 'Content-type: application/json' );
	
	if( $_SERVER['REQUEST_METHOD'] == 'HEAD' )
		exit;
	
	require_once 'geocoder.php';
	
	$responses				=	array();
	
	/*
	 *
	 *	Number of seconds to sleep after an external request, successful or not. Even if memcachedb thinks the rate isn't too fast,
	 *	we should try our best to not overload the requests. By forcibly tarpitting the response, we are sure that no one client
	 *	can run too fast by itself.
	 *	
	 *	This is not a "global" guarantee, however, as multiple incoming clients change the dyanmic. Imagine, if you will, 1000 separate PHP
	 *	instances using the geocoder client. Each will request one, and then sit for 12 seconds. But we still had a moment where we were
	 *	trying to make 1000 simultaneous requests against the gateway, and memcacheDB or Google will figure that out and rate-limit that
	 *	aspect.
	 *
	 */
	
	$tarpit					=	12;
	
	if( isset( $_REQUEST['location'] ) )
	{
		$geocoder			 	=	new Geocoder( $_REQUEST['location'] );
		
		if( isset( $_REQUEST['skipCache'] ) )
			$geocoder->skipCache();
		
		$geocoder->execute();
		
		$responses				=	$geocoder->getResponse();
		
		/*
		 *	If the response isn't internal, it was external, so tarpit
		 */
		
		if( !in_array( $responses['source'], array( 'internal', 'internal_fail' ) ) )
			usleep( $tarpit * 100000 );
	}
	
	if( isset( $_REQUEST['locations'] ) && is_array( $_REQUEST['locations'] ) )
	{
		/*
		 *	Consult the cache wholesale first - this is a big speedup with multiple locations to search
		 */
		
		if( !empty( $_REQUEST['locations'] ) && !isset( $_REQUEST['skipCache'] ) )
		{
			$geocoder				=	new Geocoder();
			
			foreach( $geocoder->readEntriesFromCache( $_REQUEST['locations'] ) as $key => $value )
			{
				$responses[$key]	=	$value;
				unset( $_REQUEST['locations'][$key] );
			}
		}
		
		if( !empty( $_REQUEST['locations'] ) )
		{
			end( $_REQUEST['locations'] );
			$lastKey					=	key( $_REQUEST['locations'] );
			
			foreach( $_REQUEST['locations'] as $key => $location)
			{
				if( isset( $_REQUEST['skipCache'] ) )
					$geocoder->skipCache();
				
				$geocoder				=	new Geocoder( $location );
				
				$geocoder->execute();
				
				$responses[$key]		=	$geocoder->getResponse();
				
				/*
				 *	If the response isn't internal, it was external, so tarpit
				 */
				
				if( !in_array( $responses[$key]['source'], array( 'internal', 'internal_fail' ) ) )
					usleep( $tarpit * 100000 );
			}
		}
	}
	
	/*
	 *	The precision argument makes for encoding JSON as completely as possible for floats. I ran into
	 *	issues with lower precisions where I wouldn't get the desired depth of accuracy.
	 */
	
	ini_set( 'precision', 20 );
	echo json_encode($responses);
	