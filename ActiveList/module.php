<?php

declare(strict_types=1);

/**
 * ActiveList - Extended Active List for IP-Symcon
 *
 * Displays all active variables in the WebFront and allows switching them off.
 *
 * Extensions compared to the original (symcon/Aktivliste):
 * - Status variable "Active" (bool):      true if at least one element is currently active
 * - Status variable "ActiveCount" (int):   Number of currently active elements
 * - Status variable "ActiveHTML" (string): HTML list of all active elements (~HTMLBox)
 *
 * These three variables are automatically updated on every value change
 * of a monitored variable (via MessageSink -> UpdateStatusVariables).
 */
class ActiveList extends IPSModule
{
    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Register configuration properties
        $this->RegisterPropertyString('VariableList', '[]');    // JSON array of monitored variable IDs
        $this->RegisterPropertyBoolean('TurnOffAction', true);  // Should the "Turn Off" button be displayed?
        $this->RegisterPropertyBoolean('EnableActive', false);      // Enable "Active" status variable (bool)
        $this->RegisterPropertyBoolean('EnableActiveCount', false); // Enable "Active Count" status variable (int)
        $this->RegisterPropertyBoolean('EnableActiveHTML', false);  // Enable "Active List" status variable (HTML)
        $this->RegisterPropertyInteger('FontSize', 0);              // Font size in px for HTML list (0 = default/inherit)
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
    }

    public function ApplyChanges()
    {
        // Never delete this line!
        parent::ApplyChanges();

        //----------------------------------------------------------------------
        // Register or unregister status variables based on configuration.
        // Variables are only created when enabled; when disabled, existing
        // variables are removed to keep the instance clean.
        //----------------------------------------------------------------------
        if ($this->ReadPropertyBoolean('EnableActive')) {
            $this->RegisterVariableBoolean('Active', $this->Translate('Active'), '~Switch', 10);
        } elseif (@$this->GetIDForIdent('Active')) {
            $this->UnregisterVariable('Active');
        }

        if ($this->ReadPropertyBoolean('EnableActiveCount')) {
            $this->RegisterVariableInteger('ActiveCount', $this->Translate('Active Count'), '', 11);
        } elseif (@$this->GetIDForIdent('ActiveCount')) {
            $this->UnregisterVariable('ActiveCount');
        }

        if ($this->ReadPropertyBoolean('EnableActiveHTML')) {
            $this->RegisterVariableString('ActiveHTML', $this->Translate('Active List'), '~HTMLBox', 12);
        } elseif (@$this->GetIDForIdent('ActiveHTML')) {
            $this->UnregisterVariable('ActiveHTML');
        }

        // Note: No EnableAction() - these variables are intentionally read-only
        // in the WebFront since they only reflect calculated status.

        //----------------------------------------------------------------------
        // Build array of configured variable IDs
        //----------------------------------------------------------------------
        $variableIDs = [];
        $variableList = json_decode($this->ReadPropertyString('VariableList'), true);
        foreach ($variableList as $line) {
            $variableIDs[] = $line['VariableID'];
        }

        //----------------------------------------------------------------------
        // Create / update links for all configured variables
        //----------------------------------------------------------------------
        foreach ($variableList as $line) {
            $variableID = $line['VariableID'];

            // Subscribe to value changes of this variable
            $this->RegisterMessage($variableID, VM_UPDATE);
            $this->RegisterReference($variableID);

            // Create link if it doesn't exist yet
            if (!@$this->GetIDForIdent('Link' . $variableID)) {
                $linkID = IPS_CreateLink();
                IPS_SetParent($linkID, $this->InstanceID);
                IPS_SetLinkTargetID($linkID, $variableID);
                IPS_SetIdent($linkID, 'Link' . $variableID);
            }

            // Always recalculate visibility for all links (new and existing),
            // so the WebFront is in sync even if values changed between ApplyChanges calls
            $linkID = @$this->GetIDForIdent('Link' . $variableID);
            if ($linkID) {
                IPS_SetHidden($linkID, (GetValue($variableID) == $this->GetSwitchValue($variableID)));
            }
        }

        //----------------------------------------------------------------------
        // Remove links whose target variable is no longer in the list
        //----------------------------------------------------------------------
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $linkID) {
            // Only check links - status variables and scripts are skipped
            if (IPS_LinkExists($linkID)) {
                if (!in_array(IPS_GetLink($linkID)['TargetID'], $variableIDs)) {
                    $this->UnregisterMessage(IPS_GetLink($linkID)['TargetID'], VM_UPDATE);
                    $this->UnregisterReference(IPS_GetLink($linkID)['TargetID']);
                    $this->UnregisterReference($linkID);
                    IPS_DeleteLink($linkID);
                }
            }
        }

        //----------------------------------------------------------------------
        // Create or remove "Turn Off" script
        //----------------------------------------------------------------------
        if ($this->ReadPropertyBoolean('TurnOffAction')) {
            $this->RegisterScript('TurnOff', $this->Translate('Turn Off'), "<?php\n\nAL_SwitchOff(IPS_GetParent(\$_IPS['SELF']));");
        } elseif (@$this->GetIDForIdent('TurnOff')) {
            IPS_DeleteScript($this->GetIDForIdent('TurnOff'), true);
        }

        //----------------------------------------------------------------------
        // Calculate initial status variable values (only if at least one enabled)
        //----------------------------------------------------------------------
        if ($this->HasEnabledStatusVariables()) {
            $this->UpdateStatusVariables();
        }
    }

    /**
     * MessageSink - called by IP-Symcon for every registered message.
     * Reacts to VM_UPDATE of monitored variables.
     */
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == VM_UPDATE) {
            // Update link visibility (original logic)
            $linkID = $this->GetIDForIdent('Link' . $SenderID);
            IPS_SetHidden($linkID, $Data[0] == $this->GetSwitchValue($SenderID));

            // Recalculate status variables only if at least one is enabled
            if ($this->HasEnabledStatusVariables()) {
                $this->UpdateStatusVariables();
            }
        }
    }

    /**
     * Switches all monitored variables to their "off" value.
     * Called via AL_SwitchOff($instanceID) or the TurnOff script.
     */
    public function SwitchOff()
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $linkID) {
            // Only process links (skip status variables and scripts)
            if (IPS_LinkExists($linkID)) {
                $targetID = IPS_GetLink($linkID)['TargetID'];

                if (IPS_VariableExists($targetID)) {
                    $v = IPS_GetVariable($targetID);

                    // Determine action: custom action takes priority over default action
                    if ($v['VariableCustomAction'] > 0) {
                        $actionID = $v['VariableCustomAction'];
                    } else {
                        $actionID = $v['VariableAction'];
                    }

                    // Only switch if a valid action exists (>= 10000)
                    // and the current value is not already the "off" value
                    if (($actionID >= 10000) && GetValue($targetID) !== $this->GetSwitchValue($targetID)) {
                        RequestAction($targetID, $this->GetSwitchValue($targetID));
                    }
                }
            }
        }
    }

    /**
     * Updates link names based on type:
     *   0 = Reset (name is inherited from link target)
     *   1 = Name of the direct parent object
     *   2 = Name of the parent object + grandparent in parentheses
     *  99 = Full location (parent object + entire path)
     */
    public function UpdateLinkNames($Type)
    {
        $variableList = json_decode($this->ReadPropertyString('VariableList'), true);
        foreach ($variableList as $line) {
            $variableID = $line['VariableID'];
            $linkID = @$this->GetIDForIdent('Link' . $variableID);
            if ($linkID) {
                $linkName = '';
                switch ($Type) {
                    case 0:
                        // Leave empty - name is inherited from the target variable
                        break;
                    case 1:
                        $linkName = IPS_GetName(IPS_GetParent($variableID));
                        break;
                    case 2:
                        $parent1 = IPS_GetParent($variableID);
                        $parent2 = IPS_GetParent($parent1);
                        $linkName = sprintf('%s (%s)', IPS_GetName($parent1), IPS_GetName($parent2));
                        break;
                    case 99:
                        $parent = IPS_GetParent($variableID);
                        $linkName = IPS_GetName($parent);
                        $location = [];
                        $parent = IPS_GetParent($parent);
                        while ($parent != 0) {
                            $location[] = IPS_GetName($parent);
                            $parent = IPS_GetParent($parent);
                        }
                        $linkName = sprintf('%s (%s)', $linkName, implode(', ', $location));
                        break;
                }
                if ($Type == 1) {
                    $linkName = IPS_GetName(IPS_GetParent($variableID));
                }
                IPS_SetName($linkID, $linkName);
            }
        }
    }

    //==========================================================================
    // NEW METHOD: Update status variables
    //==========================================================================

    /**
     * Returns true if at least one status variable is enabled in the configuration.
     * Used to skip unnecessary processing in MessageSink and ApplyChanges.
     */
    private function HasEnabledStatusVariables()
    {
        // Suppress warnings: properties may not exist on instances created before
        // this module version. In that case, treat as disabled (return false).
        return @$this->ReadPropertyBoolean('EnableActive')
            || @$this->ReadPropertyBoolean('EnableActiveCount')
            || @$this->ReadPropertyBoolean('EnableActiveHTML');
    }

    /**
     * Calculates the current status of all monitored variables and updates
     * the enabled status variables:
     *
     *   Active      (bool)   - true if at least one variable is active
     *   ActiveCount (int)    - Number of active variables
     *   ActiveHTML  (string) - HTML <ul> list of active variable names
     *
     * Each variable is only updated if enabled in the configuration.
     * "Active" means: The current value differs from the "off" value (GetSwitchValue).
     *
     * Performance note: This method iterates over all child objects of the instance
     * on every call. With a very large number of monitored variables (>100) and
     * frequent value changes, this may become noticeable. For typical use cases
     * (windows, lights, outlets) this is not an issue.
     */
    private function UpdateStatusVariables()
    {
        // Check which status variables are enabled
        // Suppress warnings: properties may not exist on pre-update instances
        $enableActive = @$this->ReadPropertyBoolean('EnableActive');
        $enableCount = @$this->ReadPropertyBoolean('EnableActiveCount');
        $enableHTML = @$this->ReadPropertyBoolean('EnableActiveHTML');

        // Skip entirely if no status variables are enabled
        if (!$enableActive && !$enableCount && !$enableHTML) {
            return;
        }

        // Guard clause: enabled status variables must exist (created in ApplyChanges).
        // MessageSink may fire before ApplyChanges (e.g. right after a module update).
        // Using falsy check since GetIDForIdent may return 0 or false for missing idents.
        if ($enableActive && !@$this->GetIDForIdent('Active')) {
            return;
        }
        if ($enableCount && !@$this->GetIDForIdent('ActiveCount')) {
            return;
        }
        if ($enableHTML && !@$this->GetIDForIdent('ActiveHTML')) {
            return;
        }

        $activeNames = [];
        $activeCount = 0;

        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            // Only process links - status variables (Active, ActiveCount, ActiveHTML)
            // and the TurnOff script are filtered out by IPS_LinkExists()
            if (!IPS_LinkExists($childID)) {
                continue;
            }

            $targetID = IPS_GetLink($childID)['TargetID'];

            // Safety check: target variable may have been deleted in the meantime
            if (!IPS_VariableExists($targetID)) {
                continue;
            }

            // Comparison: current value != "off" value -> element is active
            //
            // IMPORTANT: Use loose comparison (!=), consistent with MessageSink (==).
            // GetSwitchValue() returns bool for boolean variables and numeric values
            // for integer/float. Strict comparison (!==) would evaluate e.g.
            // int(0) !== false as true, leading to incorrect counts.
            if (GetValue($targetID) != $this->GetSwitchValue($targetID)) {
                $activeCount++;

                // Only collect names if the HTML list is enabled
                if ($enableHTML) {
                    $name = IPS_GetName($childID);
                    if ($name === '') {
                        $name = IPS_GetName($targetID);
                    }
                    $activeNames[] = $name;
                }
            }
        }

        // Update only enabled variables
        if ($enableActive) {
            $this->SetValue('Active', $activeCount > 0);
        }

        if ($enableCount) {
            $this->SetValue('ActiveCount', $activeCount);
        }

        if ($enableHTML) {
            // Sort alphabetically for consistent, user-friendly display
            sort($activeNames);
            $html = '';
            if ($activeCount > 0) {
                $fontSize = @$this->ReadPropertyInteger('FontSize');
                $style = 'margin:0; padding-left:20px;';
                if ($fontSize > 0) {
                    $style .= ' font-size:' . $fontSize . 'px;';
                }
                $html = '<ul style="' . $style . '">' . PHP_EOL;
                foreach ($activeNames as $name) {
                    $html .= '  <li>' . htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>' . PHP_EOL;
                }
                $html .= '</ul>';
            }
            $this->SetValue('ActiveHTML', $html);
        }
    }

    //==========================================================================
    // Private helper methods (unchanged from the original)
    //==========================================================================

    /**
     * Determines the "off" value of a variable based on its type and profile.
     * - Boolean: false (or true if profile is inverted via .Reversed)
     * - Integer/Float: MinValue of the profile (or MaxValue if .Reversed)
     * - String: empty string
     */
    private function GetSwitchValue($VariableID)
    {
        //Return the value corresponding to the variable type.
        switch (IPS_GetVariable($VariableID)['VariableType']) {
            //Boolean
            case 0:
                return $this->IsProfileInverted($VariableID);

                //Integer
            case 1:

                //Float
            case 2:
                if (IPS_VariableProfileExists($this->GetVariableProfile($VariableID))) {
                    if ($this->IsProfileInverted($VariableID)) {
                        return IPS_GetVariableProfile($this->GetVariableProfile($VariableID))['MaxValue'];
                    } else {
                        return IPS_GetVariableProfile($this->GetVariableProfile($VariableID))['MinValue'];
                    }
                } else {
                    return 0;
                }

                // no break
                //Integer
                // FIXME: No break. Please add proper comment if intentional
                // No break. Add additional comment above this line if intentional
            case 3:
                return '';

        }
    }

    /**
     * Returns the active profile name of a variable.
     * Custom profile takes priority over the default profile.
     */
    private function GetVariableProfile($VariableID)
    {
        $variableProfileName = IPS_GetVariable($VariableID)['VariableCustomProfile'];
        if ($variableProfileName == '') {
            $variableProfileName = IPS_GetVariable($VariableID)['VariableProfile'];
        }
        return $variableProfileName;
    }

    /**
     * Checks if a variable's profile is inverted (name ends with ".Reversed").
     * Inverted profiles reverse the on/off logic.
     */
    private function IsProfileInverted($VariableID)
    {
        return substr($this->GetVariableProfile($VariableID), -strlen('.Reversed')) === '.Reversed';
    }
}
