// Reference the existing Azure Container Registry (created outside
// Bicep) and expose its admin credentials so App Service can pull
// private images without a separate registry secret.

param registryName string

resource acr 'Microsoft.ContainerRegistry/registries@2023-01-01' existing = {
  name: registryName
}

output loginServer string = acr.properties.loginServer
output username string = acr.listCredentials().username
@secure()
output password string = acr.listCredentials().passwords[0].value