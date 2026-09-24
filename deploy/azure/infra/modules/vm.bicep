// B1s VM (12-month free) for MediaMTX (RTSP/RTMP/HLS/WebRTC) and PHP cron
// workers (import/encode/backup). Provisioned with cloud-init; the PlayTube
// repo itself is cloned manually afterwards (see deploy/azure/README.md).

param location string
param environment string
param customer string
param adminUser string
param sshPublicKey string

@description('VM size (all three are in the 12-month free 750h offer; pick the one the region has capacity for).')
@allowed([
  'Standard_B1s'
  'Standard_B1ls'
  'Standard_B2pts_v2'
])
param vmSize string = 'Standard_B1s'

var vmName = 'vm-${customer}-${environment}'
var nicName = '${vmName}-nic'
var pipName = '${vmName}-pip'
var nsgName = '${vmName}-nsg'
var vnetName = 'vnet-${vmName}'

var cloudInitScript = '''
#cloud-config
package_update: true
packages:
  - docker.io
  - docker-compose-v2
  - php-cli
  - php-mysql
  - php-mbstring
  - php-curl
  - php-zip
  - php-gd
  - php-xml
runcmd:
  - [ systemctl, enable, --now, docker ]
  - docker run -d --name mediamtx --restart unless-stopped -p 1935:1935 -p 8554:8554 -p 8888:8888 -p 8889:8889 -p 8080:8080 bluenviron/mediamtx:latest-ffmpeg
  - systemctl disable --now ufw || true
'''

resource vnet 'Microsoft.Network/virtualNetworks@2022-11-01' = {
  name: vnetName
  location: location
  properties: {
    addressSpace: {
      addressPrefixes: [ '10.20.0.0/16' ]
    }
    subnets: [
      {
        name: 'default'
        properties: {
          addressPrefix: '10.20.0.0/24'
        }
      }
    ]
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource nsg 'Microsoft.Network/networkSecurityGroups@2022-11-01' = {
  name: nsgName
  location: location
  properties: {
    securityRules: [
      { name: 'ssh', properties: { priority: 100, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '22' } }
      { name: 'mediamtx-rtsp', properties: { priority: 200, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '8554' } }
      { name: 'mediamtx-rtmp', properties: { priority: 210, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '1935' } }
      { name: 'mediamtx-hls', properties: { priority: 220, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '8888' } }
      { name: 'mediamtx-webrtc', properties: { priority: 230, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '8889' } }
      { name: 'mediamtx-api', properties: { priority: 240, direction: 'Inbound', access: 'Allow', protocol: 'Tcp', sourceAddressPrefix: '*', sourcePortRange: '*', destinationAddressPrefix: '*', destinationPortRange: '8080' } }
    ]
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource pip 'Microsoft.Network/publicIPAddresses@2022-11-01' = {
  name: pipName
  location: location
  sku: {
    name: 'Basic'
  }
  properties: {
    publicIPAllocationMethod: 'Dynamic'
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource nic 'Microsoft.Network/networkInterfaces@2022-11-01' = {
  name: nicName
  location: location
  properties: {
    networkSecurityGroup: {
      id: nsg.id
    }
    ipConfigurations: [
      {
        name: 'ipconfig1'
        properties: {
          privateIPAllocationMethod: 'Dynamic'
          publicIPAddress: {
            id: pip.id
          }
          subnet: {
            id: vnet.properties.subnets[0].id
          }
        }
      }
    ]
  }
  tags: {
    environment: environment
    customer: customer
  }
}

resource vm 'Microsoft.Compute/virtualMachines@2023-03-01' = {
  name: vmName
  location: location
  properties: {
    hardwareProfile: {
      vmSize: vmSize
    }
    osProfile: {
      computerName: vmName
      adminUsername: adminUser
      linuxConfiguration: {
        disablePasswordAuthentication: true
        ssh: {
          publicKeys: [
            {
              path: '/home/${adminUser}/.ssh/authorized_keys'
              keyData: sshPublicKey
            }
          ]
        }
      }
      customData: base64(cloudInitScript)
    }
    storageProfile: {
      imageReference: {
        publisher: 'Canonical'
        offer: '0001-com-ubuntu-server-jammy'
        sku: '22_04-lts-gen2'
        version: 'latest'
      }
      osDisk: {
        createOption: 'FromImage'
        managedDisk: {
          storageAccountType: 'Standard_LRS'
        }
      }
    }
    networkProfile: {
      networkInterfaces: [
        {
          id: nic.id
        }
      ]
    }
    diagnosticsProfile: {
      bootDiagnostics: {
        enabled: false
      }
    }
  }
  tags: {
    environment: environment
    customer: customer
  }
}

output publicIp string = pip.properties.ipAddress != null ? pip.properties.ipAddress : ''