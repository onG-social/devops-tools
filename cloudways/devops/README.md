# Git AutoDeploy for CloudWays API/PHP

This script will allow you to deploy to the various applications you have on CloudWays via GitHub. 
- You need to know your Github repository URL
- you need to label your applications you wish to deploy to automatically with the name of the branch you have in github
- you need to run this on a separate application in CloudWays

## Setting up the Deployment Script
- Create a new application on your staging server
- Give your application a name (like deploy.YOURDOMAIN.com)
- Setup SSL on your application
- Upload the contents of this directory to your application
- Make sure you do **chmod 644 .htaccess** to ensure that no one can access the files
- rename **env-sample.php** to **env.php** and edit it to include your new secret.  

**Note, this scret can be anything, but make it a strong strong, consider a GUID**

### Configure the PHP script
- You will then need to edit the **githubautodeploy.php** script in the configuration section to add your needed information. 
- **API_KEY**: This is the CloudWays API Key
- **API_URL**: This is the CloudWays API url, this usually doesn't change, and you can find it in the CloudWays API documentation
- **EMAIL**: This is the root CloudWays email address
- **GIT_URL**: This is the **SSH** url of the repository on github

## Setting up Cloudways
- For each application that you have that you wish to auto deploy to, make sure you label them with the branch id you have in github
- Make sure you go to each application and setup the Git Deployment as well - you need to add the SSH key from each environment to your GitHub, else CloudWays will NOT be able to actually work properly. You can check here: https://support.cloudways.com/en/articles/5124087-deploy-code-to-your-application-using-git

So for example, if you have 3 applications and one master application: 
**Server 1**: Production - has one application, the production application
- This branch is usually the **master** branch in GitHub
- So in CloudWays, label this application as **master**

**Server 2**: Staging - has 2 applications
- Lets assume you have a Dev environment and a staging environment
- Let's also assume that you name your Staging environment branch **staging** and your Development branch **dev-1**
- Go to each of your staging applications on the staging server, and label them as listed above. The first one would be **staging** and the second one would be labeled **dev-1**

## Setting up the Webhook in Github
- Access your repository settings
- Go to Webhooks
- Enter in the url for your gitautodeploy.php file on CloudWays (this is the application url and where you put the file)
- For the **SECRET** enter in what you put inside of the env.php file

## Testing The Webhook
- In GitHub, go to your webhook settings for your repository
- Click on the webhook deliveries tab
- Click 'redeliver' for any of the push events you have there
- Then check the response. It will tell you in detail what has happened
