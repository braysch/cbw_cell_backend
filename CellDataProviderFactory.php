<?php

namespace App\CommonClasses;

use App\CommonClasses\Telit;
use App\CommonClasses\AirVantage;
use App\CommonClasses\CellDataProvider;

class CellDataProviderFactory
{
    public static function create(string $provider): CellDataProvider
    {
        switch ($provider) {
            case 'telit':
                return new Telit(
                    'TELIT_SESSION_ID',
                    'xrdi.telit_api_url',
                    'xrdi.telit_api_username',
                    'xrdi.telit_api_password',
                    '5mb_plan',
                    '10mb_plan',
                    '30mb_plan',
                    '50mb_plan'
                );
            case 'air_vantage':
            default:
                return new AirVantage(
                    'AIR_VANTAGE_API_KEY',
                    'AIR_VANTAGE_API_REFRESH',
                    'xrdi.air_vantage_api_url',
                    'xrdi.air_vantage_api_username',
                    'xrdi.air_vantage_api_password',
                    'xrdi.air_vantage_api_client_id',
                    'xrdi.air_vantage_api_client_secret',
                    'd6d9c6e4f98e420aa47e3c996b5bcdc5',
                    '53b42b5bd5b84094b21e1f27c5e1eb5c',
                    '5a7c5bd798d94b878ffd53dbc84e2dcd',
                    '', // 50MB Plan Code
                    '/api/v1/operations/',
                    '/api/v1/systems/',
                    '/api/v1/operations/systems/activate',
                    '/api/v1/operations/systems/suspend',
                    '/api/v1/operations/systems/resume',
                    '/api/v1/operations/systems/changeoffer',
                    '/api/v1/systems/data/aggregated',
                    '/api/v1/operations/systems/network/detach',
                    '/api/oauth/token'
                );
        }
    }
}