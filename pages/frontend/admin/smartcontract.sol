// SPDX-License-Identifier: MIT
pragma solidity ^0.8.19;

/**
 * @title UserActivationSystem
 * @dev Hệ thống kích hoạt người dùng với phí từ admin - Tối ưu cho RSK Testnet
 */
contract UserActivationSystem {
    address public admin;
    uint256 public activationFee;
    uint256 public totalUsers;
    uint256 public totalActivations;
    
    enum UserRole { Admin, Producer, Distributor, User }
    enum UserStatus { Inactive, Active, Suspended }
    
    struct User {
        address userAddress;
        string userName;
        string email;
        UserRole role;
        UserStatus status;
        uint256 activationTime;
        bool exists;
    }
    
    mapping(address => User) public users;
    mapping(string => address) public emailToAddress;
    address[] public userAddresses;
    
    event UserActivated(
        address indexed userAddress,
        string userName,
        string email,
        UserRole role,
        uint256 activationTime,
        uint256 feePaid,
        address activatedBy
    );
    
    event UserStatusChanged(
        address indexed userAddress,
        UserStatus oldStatus,
        UserStatus newStatus,
        address changedBy
    );
    
    event ActivationFeeUpdated(
        uint256 oldFee,
        uint256 newFee,
        address updatedBy
    );
    
    event FundsWithdrawn(
        address indexed admin,
        uint256 amount,
        uint256 timestamp
    );
    
    modifier onlyAdmin() {
        require(msg.sender == admin, "Only admin can call this function");
        _;
    }
    
    modifier validAddress(address _address) {
        require(_address != address(0), "Invalid address");
        _;
    }
    
    constructor(uint256 _activationFee) {
        admin = msg.sender;
        activationFee = _activationFee;
    }
    
    /**
     * @dev Kích hoạt người dùng mới - chỉ admin có thể gọi
     */
    function activateUser(
        address _userAddress,
        string memory _userName,
        string memory _email,
        UserRole _role
    ) external payable onlyAdmin validAddress(_userAddress) {
        require(msg.value == activationFee, "Incorrect activation fee sent");
        require(!users[_userAddress].exists, "User already exists");
        require(emailToAddress[_email] == address(0), "Email already registered");
        
        // Tạo user mới
        users[_userAddress] = User({
            userAddress: _userAddress,
            userName: _userName,
            email: _email,
            role: _role,
            status: UserStatus.Active,
            activationTime: block.timestamp,
            exists: true
        });
        
        emailToAddress[_email] = _userAddress;
        userAddresses.push(_userAddress);
        totalUsers++;
        totalActivations++;
        totalActivations++;
        
        emit UserActivated(
            _userAddress,
            _userName,
            _email,
            _role,
            block.timestamp,
            activationFee,
            msg.sender
        );
    }
    
    /**
     * @dev Kích hoạt nhiều người dùng cùng lúc
     */
    function batchActivateUsers(
        address[] memory _userAddresses,
        string[] memory _userNames,
        string[] memory _emails,
        UserRole[] memory _roles
    ) external payable onlyAdmin {
        require(_userAddresses.length == _userNames.length, "Array length mismatch");
        require(_userAddresses.length == _emails.length, "Array length mismatch");
        require(_userAddresses.length == _roles.length, "Array length mismatch");
        require(msg.value == activationFee * _userAddresses.length, "Incorrect total fee");
        
        for (uint256 i = 0; i < _userAddresses.length; i++) {
            if (_userAddresses[i] != address(0) && 
                !users[_userAddresses[i]].exists && 
                emailToAddress[_emails[i]] == address(0)) {
                
                users[_userAddresses[i]] = User({
                    userAddress: _userAddresses[i],
                    userName: _userNames[i],
                    email: _emails[i],
                    role: _roles[i],
                    status: UserStatus.Active,
                    activationTime: block.timestamp,
                    exists: true
                });
                
                emailToAddress[_emails[i]] = _userAddresses[i];
                userAddresses.push(_userAddresses[i]);
                totalUsers++;
                totalActivations++;
                
                emit UserActivated(
                    _userAddresses[i],
                    _userNames[i],
                    _emails[i],
                    _roles[i],
                    block.timestamp,
                    activationFee,
                    msg.sender
                );
            }
        }
    }
    
    /**
     * @dev Thay đổi trạng thái người dùng
     */
    function setUserStatus(address _userAddress, UserStatus _newStatus) 
        external 
        onlyAdmin 
        validAddress(_userAddress) 
    {
        require(users[_userAddress].exists, "User does not exist");
        UserStatus oldStatus = users[_userAddress].status;
        users[_userAddress].status = _newStatus;
        
        emit UserStatusChanged(_userAddress, oldStatus, _newStatus, msg.sender);
    }
    
    /**
     * @dev Kiểm tra người dùng có active không
     */
    function isUserActive(address _userAddress) external view returns (bool) {
        return users[_userAddress].exists && users[_userAddress].status == UserStatus.Active;
    }
    
    /**
     * @dev Lấy thông tin người dùng
     */
    function getUserInfo(address _userAddress) external view returns (
        string memory userName,
        string memory email,
        UserRole role,
        UserStatus status,
        uint256 activationTime,
        bool exists
    ) {
        User memory user = users[_userAddress];
        return (
            user.userName,
            user.email,
            user.role,
            user.status,
            user.activationTime,
            user.exists
        );
    }
    
    /**
     * @dev Cập nhật phí kích hoạt
     */
    function setActivationFee(uint256 _newFee) external onlyAdmin {
        require(_newFee > 0, "Fee must be greater than 0");
        uint256 oldFee = activationFee;
        activationFee = _newFee;
        
        emit ActivationFeeUpdated(oldFee, _newFee, msg.sender);
    }
    
    /**
     * @dev Rút tiền từ hợp đồng - chỉ admin
     */
    function withdrawFees() external onlyAdmin {
        uint256 balance = address(this).balance;
        require(balance > 0, "No funds to withdraw");
        
        (bool success, ) = payable(admin).call{value: balance}("");
        require(success, "Withdrawal failed");
        
        emit FundsWithdrawn(admin, balance, block.timestamp);
    }
    
    /**
     * @dev Chuyển quyền admin
     */
    function transferAdmin(address _newAdmin) external onlyAdmin validAddress(_newAdmin) {
        require(_newAdmin != admin, "New admin must be different");
        admin = _newAdmin;
    }
    
    /**
     * @dev Lấy số dư hợp đồng
     */
    function getContractBalance() external view returns (uint256) {
        return address(this).balance;
    }
    
    /**
     * @dev Lấy tổng số user
     */
    function getTotalUsers() external view returns (uint256) {
        return totalUsers;
    }
    
    /**
     * @dev Lấy tất cả địa chỉ user
     */
    function getAllUserAddresses() external view returns (address[] memory) {
        return userAddresses;
    }
    
    /**
     * @dev Hàm nhận tiền - fallback
     */
    receive() external payable {}
    
    /**
     * @dev Hàm nhận tiền - fallback
     */
    fallback() external payable {}
}