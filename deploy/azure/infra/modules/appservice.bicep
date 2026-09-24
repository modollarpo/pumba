// App Service Linux F1 (free) plan, one code-deployed PHP app + two
// container apps (YouTube/TikTok APIs) pulling private images from ACR.

param location string
param environment string
param customer string

@description('Full ACR image ref for the PHP app, e.g. acr.azurecr.io/playtube/playtube:latest. Unused when usePlaytubeContainer is false.')
param playtubeImage string = ''

@description('When true, run the PHP app from this image; otherwise use the built-in PHP 8.2 runtime (code deploy). Defaults to built-in so no container upload is required.')
param usePlaytubeContainer bool = false

@description('Container image ref for the YouTube API (repository:tag, pulled from ACR).')
param ytApiImage string
@description('Container image ref for the TikTok API (repository:tag, pulled from ACR).')
param ttApiImage string

@description('ACR registry server (e.g. myacr.azurecr.io) for private image pulls.')
param registryServer string = ''
@description('ACR admin username.')
param registryUsername string = ''
@secure()
param registryPassword string = ''

param sqlHost string
param sqlAdminUser string
@secure()
param sqlAdminPassword string
param sqlDbName string

param siteUrl string
param purchaseCode string
@secure()
param ytApiKey string
param ytApiToken string
param ttApiToken string

var planName = 'plan-${customer}-${environment}'
var playtubeName = 'playtube-${customer}-${environment}'
var ytApiName = 'yt-api-${customer}-${environment}'
var ttApiName = 'tt-api-${customer}-${environment}'

resource plan 'Microsoft.Web/serverfarms@2022-03-01' = {
  name: planName
  location: location
  kind: 'linux'
  sku: {
    name: 'F1'
    tier: 'Free'
    size: 'F1'
    family: 'F'
    capacity: 1
  }
  properties: {
    reserved: true
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource playtube 'Microsoft.Web/sites@2023-01-01' = {
  name: playtubeName
  location: location
  kind: usePlaytubeContainer ? 'app,linux,container' : 'app,linux'
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    siteConfig: {
      linuxFxVersion: usePlaytubeContainer ? 'DOCKER|${registryServer}/${playtubeImage}' : 'PHP|8.2'
      ftpsState: 'FtpsOnly'
      appSettings: [
        { name: 'SITE_URL', value: siteUrl }
        { name: 'SQL_DB_HOST', value: sqlHost }
        { name: 'SQL_DB_USER', value: sqlAdminUser }
        { name: 'SQL_DB_PASS', value: sqlAdminPassword }
        { name: 'SQL_DB_NAME', value: sqlDbName }
        { name: 'PURCHASE_CODE', value: purchaseCode }
      ]
    }
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource ytApi 'Microsoft.Web/sites@2023-01-01' = {
  name: ytApiName
  location: location
  kind: 'app,linux,container'
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    siteConfig: {
      linuxFxVersion: 'DOCKER|${registryServer}/${ytApiImage}'
      ftpsState: 'FtpsOnly'
      appSettings: [
        { name: 'WEBSITES_PORT', value: '3000' }
        { name: 'PORT', value: '3000' }
        { name: 'WEBSITES_ENABLE_APP_SERVICE_STORAGE', value: '1' }
        { name: 'API_TOKEN', value: ytApiToken }
        { name: 'YOUTUBE_API_KEY', value: ytApiKey }
        { name: 'DOWNLOAD_DIR', value: '/home/downloads' }
        { name: 'DOCKER_REGISTRY_SERVER_URL', value: 'https://${registryServer}' }
        { name: 'DOCKER_REGISTRY_SERVER_USERNAME', value: registryUsername }
        { name: 'DOCKER_REGISTRY_SERVER_PASSWORD', value: registryPassword }
      ]
    }
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource ttApi 'Microsoft.Web/sites@2023-01-01' = {
  name: ttApiName
  location: location
  kind: 'app,linux,container'
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    siteConfig: {
      linuxFxVersion: 'DOCKER|${registryServer}/${ttApiImage}'
      ftpsState: 'FtpsOnly'
      appSettings: [
        { name: 'WEBSITES_PORT', value: '3000' }
        { name: 'PORT', value: '3000' }
        { name: 'WEBSITES_ENABLE_APP_SERVICE_STORAGE', value: '1' }
        { name: 'API_TOKEN', value: ttApiToken }
        { name: 'DOCKER_REGISTRY_SERVER_URL', value: 'https://${registryServer}' }
        { name: 'DOCKER_REGISTRY_SERVER_USERNAME', value: registryUsername }
        { name: 'DOCKER_REGISTRY_SERVER_PASSWORD', value: registryPassword }
      ]
    }
  }
  tags: {
    environment: environment
    customer: customer
  }
}

output playtubeUrl string = 'https://${playtube.properties.defaultHostName}'
output ytApiUrl string = 'https://${ytApi.properties.defaultHostName}'
output ttApiUrl string = 'https://${ttApi.properties.defaultHostName}'