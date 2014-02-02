GeolocationBase
===============

Tools for geolocation, geocoding and reverse geocoding.

Read *performance.md* for info on performance and problems.


namespace `Clevis\Geolocation`

#### basics:
 - Position
 - Circle ( *IArea* )
 - Rectangle ( *IArea* )
 - SqlGenerator

#### geocoding:
 - Address
 - Geocoder
 - Google\GeocodingClient ( *IGeocodingService*, *IReverseGeocodingService* )
 - Nominatim\GeocodingClient ( *IGeocodingService*, *IReverseGeocodingService* )
 - Google\ElevationApiClient ( *IElevationService* )
