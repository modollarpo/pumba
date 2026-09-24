// MySQL Flexible Server (B1ms = 12-month free) + always-free Blob storage.

param location string
@minLength(3)
param environment string
@minLength(3)
param customer string

param sqlAdminUser string
@secure()
param sqlAdminPassword string

@description('Optional client IPs/CIDRs allowed direct MySQL access alongside Azure services.')
param mysqlAllowedIps array = []

param mediaContainerName string

var sqlServerName = 'mysql-${customer}-${environment}'
var storageName = 'st${customer}${environment}'

resource mysql 'Microsoft.DBforMySQL/flexibleServers@2022-01-01' = {
  name: sqlServerName
  location: location
  sku: {
    name: 'Standard_B1ms'
    tier: 'Burstable'
  }
  properties: {
    administratorLogin: sqlAdminUser
    administratorLoginPassword: sqlAdminPassword
    version: '8.0'
    storage: {
      storageSizeGB: 32
    }
    backup: {
      backupRetentionDays: 7
    }
    highAvailability: {
      mode: 'Disabled'
    }
  }
  tags: {
    environment: environment
    customer: customer
  }
}

// Rule 0.0.0.0-0.0.0.0 is the special "allow Azure services" rule, so the
// App Service containers can reach MySQL.
resource mysqlFirewallAzure 'Microsoft.DBforMySQL/flexibleServers/firewallRules@2022-01-01' = {
  parent: mysql
  name: 'allow-azure-services'
  properties: {
    startIpAddress: '0.0.0.0'
    endIpAddress: '0.0.0.0'
  }
}

resource mysqlFirewallClient 'Microsoft.DBforMySQL/flexibleServers/firewallRules@2022-01-01' = [
  for (ip, i) in mysqlAllowedIps: {
    parent: mysql
    name: 'client-${i + 1}'
    properties: {
      startIpAddress: ip
      endIpAddress: ip
    }
  }
]

resource storage 'Microsoft.Storage/storageAccounts@2022-09-01' = {
  name: storageName
  location: location
  kind: 'StorageV2'
  sku: {
    name: 'Standard_LRS'
  }
  properties: {
    allowBlobPublicAccess: true
    minimumTlsVersion: 'TLS1_2'
    supportsHttpsTrafficOnly: true
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource blobService 'Microsoft.Storage/storageAccounts/blobServices@2022-09-01' = {
  parent: storage
  name: 'default'
}

resource mediaContainer 'Microsoft.Storage/storageAccounts/blobServices/containers@2022-09-01' = {
  parent: blobService
  name: mediaContainerName
  properties: {
    publicAccess: 'Blob'
  }
}

var storageKey = listKeys(storage.id, '2022-09-01').keys[0].value

output sqlHost string = '${sqlServerName}.mysql.database.azure.com'
output storageAccount string = storage.name
output storageAccountKey string = storageKey
output storageConnectionString string = 'DefaultEndpointsProtocol=https;AccountName=${storage.name};AccountKey=${storageKey};EndpointSuffix=core.windows.net'
output mediaEndpoint string = '${storage.properties.primaryEndpoints.blob}${mediaContainerName}'