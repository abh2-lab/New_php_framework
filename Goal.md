1
shift everything to core which belongs there, no system files should be exist in controllers, services and outside the core folder


2
update package.json


3
improve api tester (DocsController) with fle upload and arrays so that it can manage this input also


4
make auto-fill feature to be independent and adaptive core feature which can be used by dashboard testers and by ai orchestrator and also in cli tool if possible


5
cli tool to call api endpoints , services , repositories, auto -fill feature, direct db interaction


6
In dashboard we need to have a Settings page which will manage the project .env file adding and modify data from this and also it should have reset option to make it to default condition


7
SHOW_ERRORS, DEBUG_MODE, LOG_ERRORS they should not be directly managed by env we should have option so that we can make them on off in real time as we are using docker to run apps we should not be force to re deploy the project to make the effect of this varaibles


8
New folder structure as
 - uploads will be in src/uploads by default
 
 in api folder we should have this folders
 - api/controllers: to contain all application controllers not any system controller file
 - api/core: all the system codes so that the user should not be need to open this folder while deveoping his own application
 - api/database: as it is we have now
 - api/helpers: as it is we have now
 - api/logs:   all types of logs as it is we have now
 - api/repositories: as it is we have now
 - api/serivces: all service classes here as we have now
 - api/middleware: to have application middleware here currently it is in core but we should have here to place only middleware files are created by user



 9
 To manage all the UI part and testers and ai orchestrator we should have a solid UI management so we will be using vuejs 3 typescript script setup way to manage ui part for the framework management



 this is testing branch delete this
