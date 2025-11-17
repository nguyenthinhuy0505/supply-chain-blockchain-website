import { ethers } from "hardhat";

async function main() {
  console.log("Bắt đầu triển khai hợp đồng DangKySanPham...");

  // Lấy account deploy
  const [deployer] = await ethers.getSigners();
  console.log("Đang triển khai với account:", deployer.address);
  console.log("Số dư account:", (await deployer.provider.getBalance(deployer.address)).toString());

  // Triển khai hợp đồng
  const DangKySanPham = await ethers.getContractFactory("DangKySanPham");
  const contract = await DangKySanPham.deploy();

  await contract.waitForDeployment();
  
  const contractAddress = await contract.getAddress();
  console.log("Hợp đồng DangKySanPham đã được triển khai tại:", contractAddress);
  console.log("Chủ sở hữu hợp đồng:", await contract.chu_so_huu());

  // Verify contract (nếu cần)
  // console.log("Chờ 5 block để verify...");
  // await contract.deploymentTransaction()?.wait(5);
  
  // console.log("Verify contract với lệnh:");
  // console.log(`npx hardhat verify --network rootstock_testnet ${contractAddress}`);
}

main()
  .then(() => process.exit(0))
  .catch((error) => {
    console.error(error);
    process.exit(1);
  });