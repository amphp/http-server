# Documentation

This directory contains the documentation for `amphp/http-server`. Documentation and code are bundled within a single repository for easier maintenance. Additionally, this preserves the documentation for older versions.

## Reading

You can read this documentation either directly on GitHub or on our website. While the website will always contain the latest version, viewing on GitHub also works with older versions.

## Writing

`amphp/http-server`, as all [`amphp`](https://github.com/amphp) documentation, depends on shared parts from main [amphp.org](https://github.com/amphp/amphp.github.io) website repository. 

Setup shared parts with [`git submodules`](https://git-scm.com/docs/git-submodule) from root directory:

```bash
# initialize your local configuration file 
git submodule init

# fetch all the data from amphp/amphp.github.io project
git submodule update
```

Our documentation is built using [Jekyll](https://jekyllrb.com/). To install it, run following commands:

```bash
# Go to docs folder
cd docs

# Install Jekyll and Bundler gems through RubyGems
sudo gem install bundler jekyll

# Install required dependencies
bundle install --path vendor/bundle

# Build the site on the preview server
bundle exec jekyll serve

# Now browse to http://localhost:4000/http-server/
```
