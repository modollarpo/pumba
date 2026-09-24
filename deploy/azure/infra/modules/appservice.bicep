// App Service Linux F1 (free) plan + three container apps sharing it.
// All three apps run custom Docker images from Docker Hub (no ACR = no cost).

param location string
param environment string
param customer string

param playtubeImage string
param ytApiImage string
param ttApiImage string

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
  kind: 'app,linux,container'
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    siteConfig: {
      linuxFxVersion: 'DOCKER|index.docker.io/${playtubeImage}'
      ftpsState: 'FtpsOnly'
      appSettings: [
        { name: 'WEBSITES_PORT', value: '80' }
        { name: 'WEBSITES_ENABLE_APP_SERVICE_STORAGE', value: '1' }
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
      linuxFxVersion: 'DOCKER|index.docker.io/${ytApiImage}'
      ftpsState: 'FtpsOnly'
      appSettings: [
        { name: 'WEBSITES_PORT', value: '3000' }
        { name: 'PORT', value: '3000' }
        { name: 'WEBSITES_ENABLE_APP_SERVICE_STORAGE', value: '1' }
        { name: 'API_TOKEN', value: ytApiToken }
        { name: 'YOUTUBE_API_KEY', value: ytApiKey }
        { name: 'DOWNLOAD_DIR', value: '/home/downloads' }
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
      linuxFxVersion: 'DOCKER|index.docker.io/${ttApiImage}'
      ftpsState: 'FtpsOnly'
      appSettings: [
        { name: 'WEBSITES_PORT', value: '3000' }
        { name: 'PORT', value: '3000' }
        { name: 'WEBSITES_ENABLE_APP_SERVICE_STORAGE', value: '1' }
        { name: 'API_TOKEN', value: ttApiToken }
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