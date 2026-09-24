// PlayTube free-tier deployment for the Storegrill_Azure subscription.
//
// Deploy (from deploy/azure):
//   az deployment group create --resource-group rg-playtube-free \
//     --template-file infra/main.bicep \
//     --parameters main.parameters.json
// Or inline values, e.g.:
//   --parameters sqlAdminPassword=$env:SQL_ADMIN_PASS ytApiToken=$env:YT_API_TOKEN ...
targetScope = 'resourceGroup'

@description('Azure region. Free tiers are available in all mainstream regions.')
@allowed([
  'eastus'
  'eastus2'
  'centralus'
  'westus'
  'westus2'
  'westus3'
  'northeurope'
  'westeurope'
  'southeastasia'
  'eastasia'
  'australiaeast'
  'centralindia'
  'canadacentral'
  'uksouth'
])
param location string = 'eastus'

@description('Short environment suffix used in resource names.')
param environment string = 'free'

@description('Owner/customer tag used in resource names and tags.')
param customer string = 'storegrill'

// --- App Service containers (one F1 Linux plan, three apps) ------------------

@description('Existing ACR holding the container images (playtubeacrfree).')
param registryName string = 'playtubeacrfree'

@description('Full image ref for the PlayTube PHP app in ACR (unused unless usePlaytubeContainer is set).')
param playtubeImage string = 'playtube/playtube:latest'

@description('When true, run the PHP app as a container; default false = built-in PHP 8.2 runtime (avoids uploading the 266MB PHP context to ACR).')
param usePlaytubeContainer bool = false

@description('YouTube downloader API image (repository:tag) in ACR.')
param ytApiImage string = 'playtube/yt-api:latest'

@description('TikTok downloader API image (repository:tag) in ACR.')
param ttApiImage string = 'playtube/tt-api:latest'

// --- MySQL Flexible Server (B1ms, 12-month free) -----------------------------

@description('MySQL administrator login (also used by the app via config.php env).')
param sqlAdminUser string = 'playtube_admin'

@secure()
@description('MySQL administrator password.')
param sqlAdminPassword string

@description('MySQL database name created by the app installer/tables script.')
param sqlDbName string = 'playtube'

@description('Optional client IPs/CIDRs allowed to reach MySQL directly (in addition to Azure services).')
param mysqlAllowedIps array = []

// --- API secret material -----------------------------------------------------

@secure()
@description('YouTube Data API v3 key (starts with AIza).')
param ytApiKey string

@description('Bearer token that PlayTube uses when calling the YouTube API.')
param ytApiToken string

@description('Bearer token that PlayTube uses when calling the TikTok API.')
param ttApiToken string

@description('Public site URL without trailing slash, e.g. https://playtube-...azurewebsites.net.')
param siteUrl string

@description('Envato purchase code.')
param purchaseCode string = ''

// --- Optional MediaMTX / worker VM (B1s, 12-month free) ----------------------

@description('Deploy the B1s VM running MediaMTX plus PHP cron workers. Off by default: free-tier VM SKUs (B1s/B2pts_v2) were capacity-restricted on this subscription during validation -- flip to true once compute capacity opens up, or use an existing host.')
param deployVm bool = false

@description('SSH public key for the VM (required when deployVm is true).')
param vmAdminSshPublicKey string = ''

@description('Admin user on the VM.')
param vmAdminUser string = 'playtube'

@description('VM size; switch to Standard_B2pts_v2 if the chosen region lacks B1s capacity.')
param vmSize string = 'Standard_B1s'

// --- Storage (always-free 5 GB) ----------------------------------------------

@description('Blob container holding site media (azure_blob_storage target).')
param mediaContainerName string = 'media'

@description('Enable the free-consumption Function timer that calls cronjob.php (needed for imports/encode on F1).')
param deployCron bool = true

@description('Optional unique suffix appended to data resource names (avoids stale ARM name conflicts after a failed regional deploy).')
param dataNameSuffix string = ''

module data 'modules/data.bicep' = {
  name: 'data'
  params: {
    location: location
    environment: environment
    customer: customer
    sqlAdminUser: sqlAdminUser
    sqlAdminPassword: sqlAdminPassword
    mysqlAllowedIps: mysqlAllowedIps
    mediaContainerName: mediaContainerName
    nameSuffix: dataNameSuffix
  }
}

module appsvc 'modules/appservice.bicep' = {
  name: 'appservice'
  params: {
    location: location
    environment: environment
    customer: customer
    playtubeImage: playtubeImage
    usePlaytubeContainer: usePlaytubeContainer
    ytApiImage: ytApiImage
    ttApiImage: ttApiImage
    registryServer: acr.outputs.loginServer
    registryUsername: acr.outputs.username
    registryPassword: acr.outputs.password
    sqlHost: data.outputs.sqlHost
    sqlAdminUser: sqlAdminUser
    sqlAdminPassword: sqlAdminPassword
    sqlDbName: sqlDbName
    siteUrl: siteUrl
    purchaseCode: purchaseCode
    ytApiKey: ytApiKey
    ytApiToken: ytApiToken
    ttApiToken: ttApiToken
  }
}

module acr 'modules/acr.bicep' = {
  name: 'acr'
  params: {
    registryName: registryName
  }
}

module vm 'modules/vm.bicep' = if (deployVm) {
  name: 'mediamtx-vm'
  params: {
    location: location
    environment: environment
    customer: customer
    adminUser: vmAdminUser
    sshPublicKey: vmAdminSshPublicKey
    vmSize: vmSize
  }
}

module cron 'modules/cron.bicep' = if (deployCron) {
  name: 'cron'
  dependsOn: [
    appsvc
    data
  ]
  params: {
    location: location
    environment: environment
    customer: customer
    functionAppName: 'func-cron-${customer}-${environment}'
    planName: 'plan-cron-${customer}-${environment}'
    storageAccountName: data.outputs.storageAccount
    storageAccountKey: data.outputs.storageAccountKey
    siteUrl: appsvc.outputs.playtubeUrl
  }
}

output playtubeUrl string = appsvc.outputs.playtubeUrl
output ytApiUrl string = appsvc.outputs.ytApiUrl
output ttApiUrl string = appsvc.outputs.ttApiUrl
output sqlHost string = data.outputs.sqlHost
output storageAccount string = data.outputs.storageAccount
output mediaEndpoint string = data.outputs.mediaEndpoint
// BCP318 is a known lint false-positive: the ternary guard matches the module
// condition (no-deploy => ''), so this is safe at runtime.
output vmPublicIp string = deployVm ? vm.outputs.publicIp : ''
output cronFuncName string = deployCron ? cron.outputs.funcName : ''