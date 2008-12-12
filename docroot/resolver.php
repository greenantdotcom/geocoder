<?php
	
	require_once 'geocoder.php';
	
#	header('Content-type: text/html' );
	
#	echo "<PRE>";
	
	$responses				=	array();
	$tarpit					=	12;
	
	if( isset( $_REQUEST['location'] ) )
	{
		$geocoder			 	=	new Geocoder( $_REQUEST['location'] );
		
		if( isset( $_REQUEST['skipCache'] ) )
			$geocoder->skipCache();
		
		$geocoder->execute();
		
		$responses				=	$geocoder->getResponse();
		
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
			$lastKey				=	key( $_REQUEST['locations'] );
			
			$geocoder			=	new Geocoder( $location );
			
			foreach( $_REQUEST['locations'] as $key => $location)
			{
				if( isset( $_REQUEST['skipCache'] ) )
					$geocoder->skipCache();
				
				$geocoder->execute();
				
				$responses[$key]		=	$geocoder->getResponse();
				
				if( !in_array( $responses[$key]['source'], array( 'internal', 'internal_fail' ) ) )
					usleep( $tarpit * 100000 );
			}
		}
	}
	
	ini_set( 'precision', 20 );
	echo json_encode($responses);
