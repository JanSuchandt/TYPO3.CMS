<?php
namespace TYPO3\CMS\Core\Authentication;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authentication services class
 */
class AuthenticationService extends AbstractAuthenticationService
{
    /**
     * Process the submitted credentials.
     * In this case hash the clear text password if it has been submitted.
     *
     * @param array $loginData Credentials that are submitted and potentially modified by other services
     * @param string $passwordTransmissionStrategy Keyword of how the password has been hashed or encrypted before submission
     * @return bool
     */
    public function processLoginData(array &$loginData, $passwordTransmissionStrategy)
    {
        $isProcessed = false;
        if ($passwordTransmissionStrategy === 'normal') {
            $loginData['uident_text'] = $loginData['uident'];
            $isProcessed = true;
        }
        return $isProcessed;
    }

    /**
     * Find a user (eg. look up the user record in database when a login is sent)
     *
     * @return mixed User array or FALSE
     */
    public function getUser()
    {
        if ($this->login['status'] !== LoginType::LOGIN) {
            return false;
        }
        if ((string)$this->login['uident_text'] === '') {
            // Failed Login attempt (no password given)
            $this->writelog(255, 3, 3, 2, 'Login-attempt from ###IP### (%s) for username \'%s\' with an empty password!', [
                $this->authInfo['REMOTE_HOST'], $this->login['uname']
            ]);
            $this->logger->warning(sprintf('Login-attempt from %s (%s), for username \'%s\' with an empty password!', $this->authInfo['REMOTE_ADDR'], $this->authInfo['REMOTE_HOST'], $this->login['uname']));
            return false;
        }

        $user = $this->fetchUserRecord($this->login['uname']);
        if (!is_array($user)) {
            // Failed login attempt (no username found)
            $this->writelog(255, 3, 3, 2, 'Login-attempt from ###IP### (%s), username \'%s\' not found!!', [$this->authInfo['REMOTE_HOST'], $this->login['uname']]);
            $this->logger->info('Login-attempt from username \'' . $this->login['uname'] . '\' not found!', [
                'REMOTE_ADDR' => $this->authInfo['REMOTE_ADDR'],
                'REMOTE_HOST' => $this->authInfo['REMOTE_HOST'],
            ]);
        } else {
            $this->logger->debug('User found', [
                $this->db_user['userid_column'] => $user[$this->db_user['userid_column']],
                $this->db_user['username_column'] => $user[$this->db_user['username_column']]
            ]);
        }
        return $user;
    }

    /**
     * Authenticate a user (Check various conditions for the user that might invalidate its authentication, eg. password match, domain, IP, etc.)
     *
     * @param array $user Data of user.
     * @return int >= 200: User authenticated successfully.
     *                     No more checking is needed by other auth services.
     *             >= 100: User not authenticated; this service is not responsible.
     *                     Other auth services will be asked.
     *             > 0:    User authenticated successfully.
     *                     Other auth services will still be asked.
     *             <= 0:   Authentication failed, no more checking needed
     *                     by other auth services.
     */
    public function authUser(array $user)
    {
        $OK = 100;
        // This authentication service can only work correctly, if a non empty username along with a non empty password is provided.
        // Otherwise a different service is allowed to check for other login credentials
        if ((string)$this->login['uident_text'] !== '' && (string)$this->login['uname'] !== '') {
            // Checking password match for user:
            $OK = $this->compareUident($user, $this->login);
            if (!$OK) {
                // Failed login attempt (wrong password) - write that to the log!
                if ($this->writeAttemptLog) {
                    $this->writelog(255, 3, 3, 1, 'Login-attempt from ###IP### (%s), username \'%s\', password not accepted!', [$this->authInfo['REMOTE_HOST'], $this->login['uname']]);
                    $this->logger->info('Login-attempt username \'' . $this->login['uname'] . '\', password not accepted!', [
                        'REMOTE_ADDR' => $this->authInfo['REMOTE_ADDR'],
                        'REMOTE_HOST' => $this->authInfo['REMOTE_HOST'],
                    ]);
                }
                $this->logger->debug('Password not accepted: ' . $this->login['uident']);
            }
            // Checking the domain (lockToDomain)
            if ($OK && $user['lockToDomain'] && $user['lockToDomain'] !== $this->authInfo['HTTP_HOST']) {
                // Lock domain didn't match, so error:
                if ($this->writeAttemptLog) {
                    $this->writelog(255, 3, 3, 1, 'Login-attempt from ###IP### (%s), username \'%s\', locked domain \'%s\' did not match \'%s\'!', [$this->authInfo['REMOTE_HOST'], $user[$this->db_user['username_column']], $user['lockToDomain'], $this->authInfo['HTTP_HOST']]);
                    $this->logger->info('Login-attempt from username \'' . $user[$this->db_user['username_column']] . '\', locked domain did not match!', [
                        'HTTP_HOST' => $this->authInfo['HTTP_HOST'],
                        'REMOTE_ADDR' => $this->authInfo['REMOTE_ADDR'],
                        'REMOTE_HOST' => $this->authInfo['REMOTE_HOST'],
                        'lockToDomain' => $user['lockToDomain'],
                    ]);
                }
                $OK = 0;
            }
        }
        return $OK;
    }

    /**
     * Find usergroup records, currently only for frontend
     *
     * @param array $user Data of user.
     * @param array $knownGroups Group data array of already known groups. This is handy if you want select other related groups. Keys in this array are unique IDs of those groups.
     * @return mixed Groups array, keys = uid which must be unique
     */
    public function getGroups($user, $knownGroups)
    {
        /*
         * Attention: $knownGroups is not used within this method, but other services can use it.
         * This parameter should not be removed!
         * The FrontendUserAuthentication call getGroups and handover the previous detected groups.
         */
        $groupDataArr = [];
        if ($this->mode === 'getGroupsFE') {
            $groups = [];
            if ($user[$this->db_user['usergroup_column']] ?? false) {
                $groupList = $user[$this->db_user['usergroup_column']];
                $groups = [];
                $this->getSubGroups($groupList, '', $groups);
            }
            // ADD group-numbers if the IPmask matches.
            foreach ($GLOBALS['TYPO3_CONF_VARS']['FE']['IPmaskMountGroups'] ?? [] as $IPel) {
                if ($this->authInfo['REMOTE_ADDR'] && $IPel[0] && GeneralUtility::cmpIP($this->authInfo['REMOTE_ADDR'], $IPel[0])) {
                    $groups[] = (int)$IPel[1];
                }
            }
            $groups = array_unique($groups);
            if (!empty($groups)) {
                $this->logger->debug('Get usergroups with id: ' . implode(',', $groups));
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
                    ->getQueryBuilderForTable($this->db_groups['table']);
                if (!empty($this->authInfo['showHiddenRecords'])) {
                    $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
                }

                $res = $queryBuilder->select('*')
                    ->from($this->db_groups['table'])
                    ->where(
                        $queryBuilder->expr()->in(
                            'uid',
                            $queryBuilder->createNamedParameter($groups, Connection::PARAM_INT_ARRAY)
                        ),
                        $queryBuilder->expr()->orX(
                            $queryBuilder->expr()->eq(
                                'lockToDomain',
                                $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                            ),
                            $queryBuilder->expr()->isNull('lockToDomain'),
                            $queryBuilder->expr()->eq(
                                'lockToDomain',
                                $queryBuilder->createNamedParameter($this->authInfo['HTTP_HOST'], \PDO::PARAM_STR)
                            )
                        )
                    )
                    ->execute();

                while ($row = $res->fetch()) {
                    $groupDataArr[$row['uid']] = $row;
                }
            } else {
                $this->logger->debug('No usergroups found.');
            }
        }
        return $groupDataArr;
    }

    /**
     * Fetches subgroups of groups. Function is called recursively for each subgroup.
     * Function was previously copied from
     * \TYPO3\CMS\Core\Authentication\BackendUserAuthentication->fetchGroups and has been slightly modified.
     *
     * @param string $grList Commalist of fe_groups uid numbers
     * @param string $idList List of already processed fe_groups-uids so the function will not fall into an eternal recursion.
     * @param array $groups
     * @return array
     * @access private
     */
    public function getSubGroups($grList, $idList = '', &$groups)
    {
        // Fetching records of the groups in $grList (which are not blocked by lockedToDomain either):
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('fe_groups');
        if (!empty($this->authInfo['showHiddenRecords'])) {
            $queryBuilder->getRestrictions()->removeByType(HiddenRestriction::class);
        }

        $res = $queryBuilder
            ->select('uid', 'subgroup')
            ->from($this->db_groups['table'])
            ->where(
                $queryBuilder->expr()->in(
                    'uid',
                    $queryBuilder->createNamedParameter(
                        GeneralUtility::intExplode(',', $grList, true),
                        Connection::PARAM_INT_ARRAY
                    )
                ),
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq(
                        'lockToDomain',
                        $queryBuilder->createNamedParameter('', \PDO::PARAM_STR)
                    ),
                    $queryBuilder->expr()->isNull('lockToDomain'),
                    $queryBuilder->expr()->eq(
                        'lockToDomain',
                        $queryBuilder->createNamedParameter($this->authInfo['HTTP_HOST'], \PDO::PARAM_STR)
                    )
                )
            )
            ->execute();

        // Internal group record storage
        $groupRows = [];
        // The groups array is filled
        while ($row = $res->fetch()) {
            if (!in_array($row['uid'], $groups)) {
                $groups[] = $row['uid'];
            }
            $groupRows[$row['uid']] = $row;
        }
        // Traversing records in the correct order
        $include_staticArr = GeneralUtility::intExplode(',', $grList);
        // traversing list
        foreach ($include_staticArr as $uid) {
            // Get row:
            $row = $groupRows[$uid];
            // Must be an array and $uid should not be in the idList, because then it is somewhere previously in the grouplist
            if (is_array($row) && !GeneralUtility::inList($idList, $uid)) {
                // Include sub groups
                if (trim($row['subgroup'])) {
                    // Make integer list
                    $theList = implode(',', GeneralUtility::intExplode(',', $row['subgroup']));
                    // Call recursively, pass along list of already processed groups so they are not processed again.
                    $this->getSubGroups($theList, $idList . ',' . $uid, $groups);
                }
            }
        }
    }
}
