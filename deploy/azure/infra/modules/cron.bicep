// Consumption Function App that hits the site's cronjob.php on a timer.
// Consumption is always-free (1M executions/month). Reuses the media
// storage account so the app can pull/queue work via the PHP worker.

param location string
param environment string
param customer string

param functionAppName string
param planName string

param storageAccountName string
@secure()
param storageAccountKey string

param siteUrl string

resource plan 'Microsoft.Web/serverfarms@2022-03-01' = {
  name: planName
  location: location
  sku: {
    name: 'Y1'
    tier: 'Dynamic'
    size: 'Y1'
    family: 'Y'
    capacity: 0
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource funcApp 'Microsoft.Web/sites@2023-01-01' = {
  name: functionAppName
  location: location
  kind: 'functionapp'
  properties: {
    serverFarmId: plan.id
    httpsOnly: true
    siteConfig: {
      appSettings: [
        { name: 'AzureWebJobsStorage', value: 'DefaultEndpointsProtocol=https;AccountName=${storageAccountName};AccountKey=${storageAccountKey};EndpointSuffix=core.windows.net' }
        { name: 'FUNCTIONS_WORKER_RUNTIME', value: 'node' }
        { name: 'FUNCTIONS_EXTENSION_VERSION', value: '~4' }
        { name: 'WEBSITE_NODE_DEFAULT_VERSION', value: '~20' }
        { name: 'CRON_URL', value: '${siteUrl}/cronjob.php' }
      ]
    }
  }
  tags: {
    environment: environment
    customer: customer
  }
}

output funcName string = funcApp.name