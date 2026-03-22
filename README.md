# Advanced Registration Logic

Version: 1.0.0

Advanced Registration Logic provides configurable registration behavior, including:
- Optional multi-step registration flow.
- Admin-defined field order.
- Admin-defined field descriptors (hints).
- Group to profile category mapping for group-specific fields.

## Features
- Multi-step registration (enable/disable in module settings).
- Step indicator ("Step X of Y") when multi-step is enabled.
- Field ordering and descriptors apply to both single-step and multi-step modes.
- Group-based profile field selection in step 2 (multi-step mode).

## Configuration
Go to **Admin → Modules → Advanced Registration Logic → Configure**.

### Enable multi-step
Toggle **Enable multi-step registration flow**.

### Field order
Enter one field per line in `Model.field` format:
```
User.username
Password.newPassword
Password.newPasswordConfirm
GroupUser.group_id
Profile.firstname
```

### Field descriptors (hints)
Enter one per line in `FieldKey|Description` format:
```
GroupUser.group_id|Please select appropriate group...
Password.newPassword|Use at least 8 characters
```

### Group to category mapping
Enter one per line in `Group Name|Profile Field Category Name` format:
```
PPI Members|PPI Members
Professional Members|Professional Members
```

If empty, the group name is used as the category name.

## Notes
- Multi-step is handled by `advancedregistrationlogic/registration/index`.
- The module redirects the core registration route to the module controller.
- No database migrations are required.

## Copyright
Copyright (c) 2026 D Cube Consulting Ltd. All rights reserved.
