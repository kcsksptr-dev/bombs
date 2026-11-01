.SILENT:
.PHONY: help

help:
	awk '/^[a-zA-Z_0-9-]+:/ { \
		comment = match(line, /^## (.*)/); \
		if (comment) { \
			command = substr($$1, 0, index($$1, ":")); \
			description = substr(line, RSTART + 3, RLENGTH); \
			printf "%-32s %s\n", command, description; \
		} \
	} \
	{ line = $$0 }' $(MAKEFILE_LIST)

include .makefile/local.mk
