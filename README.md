# Bloomreach Feed Module

The Feed module provides a means to submit your magento data to the
Bloomreach service.

- THE INTENT of this module is to provide a base example of how to upload
  magento's product catalog to bloomreach. It is VERY abundant that everyone has
  a unique approach to magento and very different needs, so this module is
  useful, but should be viewed as a guideline rather than a full solution to
  YOUR specific product.

- YOU CAN use this module directly in a default magento project. If you have any
  custom configuration or overrides of default magento behavior then:

- YOU SHOULD fork this project and modify it as needed for your specific use
  case. The most interesting class for you to modify / override would be
  `BrProductTransformer` and `AttributeProcessor` where you can adjust your product conversions. 
  You can look at `SubmitProductsApiController` to see the API endpoint and make
  adjustments to the batch processing this module uses.

Items of interest:

Note that this module does its best to assume you have a MASSIVE product
catalog and does its best to batch process and write to the local disk of the
server to offload eating up all the server's RAM. Writing to the disk can
pose their own set of issues as well depending on your cloud service you are
using, so this is an area you may need to customize as well to make the module
run smoothly for your environment.

Currently, this module does NOT delete the file generated to be fed to
Bloomreach, located in the `var/export/bloomreach_feed` directory of the project. 
This is for debugging and this behavior should be customized, again, as your project needs.

## Base requirements for your project

This module utilizes the Magento Async API pattern, so you MUST have the proper
consumer running along with the proper cron configuration:

```sh
bin/magento queue:consumers:start async.operations.all
bin/magento cron:run --group="async_operations"
```

You must ensure async operations ARE working properly and that operations are
getting dequeued and processed.

## Installing Magento for development

- Ensure you have installed Docker and it is up to date
- Go to https://marketplace.magento.com and create an account or log into that
  portal
- Navigate to `https://marketplace.magento.com/customer/accessKeys/`
- Have a key ready to be used (create a new one if you need one. Key name does
  not matter)

Please then run:

```sh
npm i
npm run magento-install
```

- You will be prompted for a username and password:
  UserName = Public Key from marketplace.magento.com
  Password = Private Key from marketplace.magento.com

- You will later be asked to enter your system password.

After completing all the steps the server should be running.

Troubleshooting:

All the videos to understand magento set up via docker is discussed here:
https://courses.m.academy/courses/set-up-magento-2-development-environment-docker/lectures/9064350

- Many issues can be resolved by reinstalling
  ```
  npm run magento-uninstall
  npm run magento-install
  ```
- If you have a chmod failure, there is a chance your Adobe keys were not
  entered in correctly. Please remember to NOT use your account user/pass and
  use the public and private keys as user/pass.
  - Once the creds have failed they get cached on your local machine. You can
    find the creds at: `~/.composer/auth.json`. Modify the values there to be
    correct to make the install script work properly.
- If your demo data didn't load: try running:
  ```sh
  # Note, the . at the beginning makes it easier to cd into the folder first or
  # bash will interpret the . as an execute command instead of the path.
  cd .magento
  bin/magento sampledata:deploy
  ```
  or reinstall

## Developing

After magento is installed, you can being developing by using:

```sh
# Ensure docker desktop is running
npm run dev
```

THe process will be completed when you see the message:

`Development server started.`

This will start your magento instance and make the URL `https://magento.test`
available on your local machine. This will also automatically distribute changes
to your magento server for viewing in the local environment.

Most changes will appear automatically, but some changes will require magento
commands such as the following:

```sh
cd .magento
# Run when you change any dependency injected parameters in your classes
bin/magento setup:di:compile
# Run when all else fails
bin/magento setup:upgrade
# Run when you think something should be there but isn't loading in the browser
bin/magento cache:flush
# Run if the async operation queue is not processing Submission requests
# If you make changes to the Async API endpoint, you have to stop and start this
# again to see changes take effect
bin/magento queue:consumers:start async.operations.all
# Run if the submission operations are still not processing
bin/magento cron:run --group="async_operations"
```

To see debug logs go to:

`.magento/docker-compose.dev.yml`

and uncomment the line:

`- ./src/var/log:/var/www/html/var/log:cached`

Your logs can be seen if you run:

```sh
tail -f .magento/src/var/log/debug.log
```

When you are done developing, if quitting the developer script does not
succeed, you can ensure the magento server and resources are stopped via:

```sh
bin/stop
```

NOTE: You can use the opposite to run your server without any special npm
commands:

```sh
bin/start
```

## Configure App First

To integrate any options from this extension you need to fill app configuration settings.
To do this you can follow these steps to trigger index.

- Step 1: Login to admin, if not already logged-in
- Step 2: Goto Store->Configuration
- Step 3: Find "Bloomreach Feed" Section
- Step 4: Click on "Settings" Tab under "Bloomreach Feed" Section
- Step 5: Fill all options: You can get these setting values from your Bloomreach account.
  - Account Id
  - Catalog Name
  - API Key: This key is sent to you in an email when you create your Bloomreach account. This is NOT the auth key that is found in your developer profile
  - Target Environment: Production or Staging
  - Attribute Transformations: You can customize some of the attribute transformations here. 
  For multivalued attributes, separate the values by a comma (`,`):
    - Name Mappings: Attributes that should be named differently in Bloomreach than in Magento. 
      Note: One Magento attribute can be mapped to multiple Bloomreach attributes
    - Index-value Attributes: Attributes that should use their index value (instead of text label)
    - Variant-only Attributes: Attributes that should ONLY appear in variants (not in main products)
    - Skip Attributes: Attributes that should NEVER be included (neither main products nor variants)

## Trigger Feed Submission

When you are ready to submit your Magento catalog to Bloomreach, 
you can trigger the feed submission following these steps:

- Step 1: Login to admin, if not already logged-in
- Step 2: Goto Store->Configuration
- Step 3: Find "Bloomreach Feed" Section
- Step 4: Click on "Submit Product Catalog" Tab under "Bloomreach Feed" Section
- Step 5: Now click on button named "Submit Data to Bloomreach"

## Feed Submission History

You can review the Submission History in the same "Submit Product Catalog" tab. 

- Click on the "Refresh" button to fetch the latest status.
- Click "View details" link in the "Messages" column to view the verbose messages in a popup.
- If there is a feed file, you can download it by clicking on the link in the "Feed File" column. 
  The feed file is in [JSON Lines](https://jsonlines.org) format.
