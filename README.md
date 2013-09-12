Package Builder
==============

Installation:
  Copy Package Builder directory to any location where your user has full read/write permissions.

Usage:
  This application uses the upstream repository to create the package.  All tags and release branches 
  must be on the upstream repository master branch.  Release branches must start with "release-". 
  There must also be a initial version tag already on the repository if this is being used to create 
  the first release package on this repository.

  -Run "php build.php" from command line.
  -Enter the path to your local git repository containing SugarCRM.
  -Option 1: Choose this option if you haven't already merged and tagged a release on the master branch.
    -Select the release branch.
    -Merge with local master branch and create version tag.
    -Push changes and tags to upstream repository.
    -If upstream repository is on GitHub you will be prompted for credentials.
    -Delete release branch (no reason to keep it after merge)
  -Option 2 (and Option 1)
    -Select the current production version from the list.
    -Select the new release version from the list.
    -Create a new build between these 2 versions.
    -A package folder and zip file will be created in the "packages" directory
  -Install package zip file using SugarCRM Module Loader

TODO
  -Exclude files not allowed by Sugar On Demand
  -Add some additional messaging to what is going on in the background
  -Add messaging to tell where newly created package is located after a successful build
